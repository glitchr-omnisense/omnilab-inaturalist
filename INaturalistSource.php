<?php

namespace Omnilab\INaturalist;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * iNaturalist (California Academy of Sciences and National Geographic
 * Society), through its API (api.inaturalist.org): a taxon by its id or a
 * name it matches exactly - its rank, common name in a language, iconic
 * group, conservation status, lineage, Wikipedia summary, number of
 * observations, its default photo with its licence and attribution; its
 * parent (or, for an inactive taxon, the one that replaced it) as a related
 * thing; a search of its names. And the observations of a place and a
 * period - research grade by default -, each an entity with its time and
 * its place, its taxon related, its licence and its photo's (occurrences(),
 * entity() by its number).
 *
 * Which API: version 1 for the taxa (a taxon whole in one call); version 2
 * for the observations, which answers only the fields asked for - a page of
 * 200 lion observations weighs 44 MB in version 1 (every identification,
 * comment and vote of each), 0.18 MB so.
 *
 * No key. iNaturalist throttles at 100 calls a minute, asks for 60 at most
 * and 10,000 a day, and blocks without notice a use that weighs on it: one
 * call a second. iNaturalist gives no identifier of another base for a
 * taxon: Wikidata (P3151) is the way between them.
 */
final class INaturalistSource extends HttpSource
{
    public const BASE_URI = 'https://api.inaturalist.org/';

    /** Seconds between two calls: iNaturalist's 60 a minute. */
    public const THROTTLE = 1.0;

    /** The most taxa or observations one page holds (iNaturalist stops at 200). */
    public const MAX_PER_PAGE = 200;

    /** How deep a search pages by page number: page times size (iNaturalist's error beyond). */
    public const MAX_DEPTH = 10000;

    /** The quality grades of an observation: research (identified by the community, dated, placed, wild, with evidence), needs_id, casual. */
    public const QUALITY_GRADES = ['research', 'needs_id', 'casual'];

    /** The licences iNaturalist filters by. */
    public const LICENSES = ['cc0', 'cc-by', 'cc-by-nc', 'cc-by-sa', 'cc-by-nd', 'cc-by-nc-sa', 'cc-by-nc-nd'];

    /** iNaturalist's own filter: the quality grade, or a list of them; "any" for all. */
    public const QUALITY_GRADE = 'quality_grade';

    /** The ranks given each a property, from the root down. */
    private const RANKS = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /** The IUCN Red List's categories, by code. */
    private const IUCN = ['EX' => 'Extinct', 'EW' => 'Extinct in the wild', 'CR' => 'Critically endangered', 'EN' => 'Endangered', 'VU' => 'Vulnerable', 'NT' => 'Near threatened', 'LC' => 'Least concern', 'DD' => 'Data deficient', 'NE' => 'Not evaluated'];

    /** What version 2 is asked of an observation. */
    private const OBSERVATION_FIELDS = 'id,observed_on,time_observed_at,observed_time_zone,quality_grade,license_code,location,obscured,geoprivacy,taxon_geoprivacy,positional_accuracy,public_positional_accuracy,place_guess,species_guess,captive,description,num_identification_agreements,num_identification_disagreements,taxon.id,taxon.name,taxon.rank,taxon.preferred_common_name,taxon.iconic_taxon_name,user.login,user.name,photos.url,photos.license_code,photos.attribution,sounds.id';

    /**
     * @param string $locale  the language of the common names
     * @param string $quality the quality grade asked when a query does not say ("any": all)
     * @param float  $radius  the radius around a Point, in kilometres, for occurrences() when the query gives none
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $locale = 'en',
        private readonly string $quality = 'research',
        private readonly float $radius = 10.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = self::THROTTLE,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'inaturalist';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES, Capability::AREA];
    }

    /**
     * A taxon by its id (41964, inaturalist:41964, its page) or a name
     * iNaturalist matches exactly - a scientific name, a synonym ("Felis
     * leo" gives Panthera leo) or a common name; an observation by its
     * number (inaturalist.observation:332912788, its page): one call, two
     * for a name.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::INATURALIST, Scheme::INATURALIST_OBSERVATION];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('inaturalist', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::INATURALIST, $text) ?? Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('inaturalist', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }
        if (Scheme::INATURALIST_OBSERVATION === $identifier->scheme) {
            $data = $this->getJson('v2/observations', ['id' => $identifier->value, 'fields' => self::OBSERVATION_FIELDS, 'locale' => $this->locale]);
            $record = $data['results'][0] ?? null;

            return \is_array($record) ? $this->toObservation($record) : null;
        }

        return $this->taxon($identifier->value);
    }

    /**
     * iNaturalist's search of its taxa's names - scientific, common, former
     * -, active taxa only, its order (the most observed first, broadly),
     * page by page. A domain other than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text) {
            return new Page();
        }
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $page = max(1, (int) $query->cursor);
        if ($page * $size > self::MAX_DEPTH) {
            throw NotSupportedException::operation('inaturalist', \sprintf('page past the %d first taxa of a search', self::MAX_DEPTH));
        }
        $data = $this->getJson('v1/taxa', ['q' => $text, 'is_active' => 'true', 'per_page' => $size, 'page' => $page > 1 ? $page : null, 'locale' => $this->locale]) ?? [];
        $entities = [];
        foreach ($data['results'] ?? [] as $taxon) {
            if (\is_array($taxon) && isset($taxon['id'], $taxon['name'])) {
                $entities[] = $this->toTaxon($taxon);
            }
        }
        $total = isset($data['total_results']) ? (int) $data['total_results'] : null;
        $more = null !== $total && $page * $size < $total && ($page + 1) * $size <= self::MAX_DEPTH;

        return new Page($entities, $more ? (string) ($page + 1) : null, $total);
    }

    /**
     * The observations of the place and the period, each an entity with its
     * time and its place, its taxon related: research grade unless the
     * query or the source's option says otherwise; only the georeferenced.
     *
     * A BBox is the box (across the antimeridian too: iNaturalist reads it);
     * a Point, the circle of the query's radius around it (the configured
     * one, 10 km, by default); no place, the world. The period: a bound at
     * midnight is its whole day, any other the instant. Filters:
     * OccurrenceQuery::TAXON (an id, inaturalist:41964 - its subordinate
     * taxa included -, or a name iNaturalist matches), LICENSE (cc0, cc-by,
     * cc-by-nc... or a list: the observation's licence) and quality_grade
     * (research, needs_id, casual, a list, or "any"). Sort: none, the
     * observations latest added first (paged by id, no depth limit), or
     * NEWEST / OLDEST by the day observed (paged by number, 10,000 deep). 200
     * a page at most; the total is iNaturalist's count, on the first page.
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::TAXON, OccurrenceQuery::LICENSE, self::QUALITY_GRADE);
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $parameters = $this->taxonFilter($filters[OccurrenceQuery::TAXON] ?? null) + [
            'quality_grade' => $this->grades($filters[self::QUALITY_GRADE] ?? $this->quality),
            'license' => $this->licenses($filters[OccurrenceQuery::LICENSE] ?? null),
            'geo' => 'true',
            'd1' => self::bound($query->from),
            'd2' => self::bound($query->to),
        ] + $this->area($query);
        $byId = null === $query->sort;
        if ($byId) {
            $below = null === $query->cursor ? null : (preg_match('~^below:(\d+)$~', $query->cursor, $m) ? $m[1] : throw new \InvalidArgumentException(\sprintf('"%s" is no cursor of this source.', $query->cursor)));
            $parameters += ['order_by' => 'id', 'order' => 'desc', 'id_below' => $below];
        } else {
            $page = max(1, (int) $query->cursor);
            if (!\in_array($query->sort, [OccurrenceQuery::NEWEST, OccurrenceQuery::OLDEST], true)) {
                throw NotSupportedException::operation('inaturalist', \sprintf('sort occurrences by "%s" (it sorts by "%s", "%s", or none)', $query->sort, OccurrenceQuery::NEWEST, OccurrenceQuery::OLDEST));
            }
            if ($page * $size > self::MAX_DEPTH) {
                throw NotSupportedException::operation('inaturalist', \sprintf('page past the %d first observations of a sorted query (unsorted, it pages without a limit)', self::MAX_DEPTH));
            }
            $parameters += ['order_by' => 'observed_on', 'order' => OccurrenceQuery::OLDEST === $query->sort ? 'asc' : 'desc', 'page' => $page > 1 ? $page : null];
        }
        $data = $this->getJson('v2/observations', $parameters + ['per_page' => $size, 'fields' => self::OBSERVATION_FIELDS, 'locale' => $this->locale]) ?? [];
        $records = array_values(array_filter($data['results'] ?? [], static fn (mixed $r) => \is_array($r) && isset($r['id'])));
        // A casual observation may lack a day: left out, as every occurrence has its time and its place.
        $entities = array_values(array_filter(array_map($this->toObservation(...), $records), static fn (Entity $e) => null !== $e->time && null !== $e->place));
        $total = isset($data['total_results']) ? (int) $data['total_results'] : null;
        $full = \count($records) === $size;
        if ($byId) {
            $next = $full && null !== $total && $total > $size ? 'below:'.end($records)['id'] : null;

            return new Page($entities, $next, null === $query->cursor ? $total : null);
        }
        $more = null !== $total && $page * $size < $total && ($page + 1) * $size <= self::MAX_DEPTH;

        return new Page($entities, $more ? (string) ($page + 1) : null, $total);
    }

    /**
     * A period's bound as iNaturalist reads it: a date for a bound at
     * midnight (its whole day), else the instant with its offset.
     */
    private static function bound(?\DateTimeInterface $bound): ?string
    {
        return match (true) {
            null === $bound => null,
            '00:00:00' === $bound->format('H:i:s') => $bound->format('Y-m-d'),
            default => $bound->format(\DATE_ATOM),
        };
    }

    /** @return array<string, mixed> */
    private function area(OccurrenceQuery $query): array
    {
        $place = $query->place;

        return match (true) {
            $place instanceof Point => ['lat' => $place->latitude, 'lng' => $place->longitude, 'radius' => $query->radius ?? $this->radius],
            null === $place => [],
            // iNaturalist reads a box whose west is east of its east as one across the antimeridian.
            default => ['swlat' => $place->south, 'swlng' => $place->west, 'nelat' => $place->north, 'nelng' => $place->east],
        };
    }

    /** @return array<string, mixed> */
    private function taxonFilter(mixed $taxon): array
    {
        if (null === $taxon) {
            return [];
        }
        if ($taxon instanceof Identifier) {
            return Scheme::INATURALIST === $taxon->scheme ? ['taxon_id' => $taxon->value] : throw NotSupportedException::identifier('inaturalist', (string) $taxon, 'filter occurrences by');
        }
        if (!\is_string($taxon) && !\is_int($taxon)) {
            throw new \InvalidArgumentException(\sprintf('A taxon is an Identifier of inaturalist, an id or a name, not %s.', get_debug_type($taxon)));
        }
        $text = trim((string) $taxon);
        $identifier = Identifier::tryOf(Scheme::INATURALIST, $text) ?? Identifier::parse($text);
        if (null !== $identifier && Scheme::INATURALIST !== $identifier->scheme) {
            throw NotSupportedException::identifier('inaturalist', $text, 'filter occurrences by');
        }

        return null === $identifier ? ['taxon_name' => $text] : ['taxon_id' => $identifier->value];
    }

    private function grades(mixed $grades): ?string
    {
        $grades = array_map(static fn (mixed $g) => strtolower(trim((string) $g)), (array) $grades);
        if (\in_array('any', $grades, true)) {
            return null;
        }
        foreach ($grades as $grade) {
            if (!\in_array($grade, self::QUALITY_GRADES, true)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is no quality grade of iNaturalist (%s, or any).', $grade, implode(', ', self::QUALITY_GRADES)));
            }
        }

        return implode(',', $grades);
    }

    private function licenses(mixed $licenses): ?string
    {
        if (null === $licenses) {
            return null;
        }
        $licenses = array_map(static fn (mixed $l) => strtolower(trim((string) $l)), (array) $licenses);
        foreach ($licenses as $license) {
            if (!\in_array($license, self::LICENSES, true)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is no licence iNaturalist filters by (%s).', $license, implode(', ', self::LICENSES)));
            }
        }

        return implode(',', $licenses);
    }

    private function taxon(string $id): ?Entity
    {
        $taxon = $this->getJson('v1/taxa/'.$id, ['locale' => $this->locale])['results'][0] ?? null;

        return \is_array($taxon) && isset($taxon['id'], $taxon['name']) ? $this->toTaxon($taxon) : null;
    }

    /** A name matched exactly - the taxon's own, or a synonym or a common name it goes by -, then the taxon read whole. */
    private function byName(string $name): ?Entity
    {
        $results = $this->getJson('v1/taxa', ['q' => $name, 'is_active' => 'true', 'per_page' => 30, 'locale' => $this->locale])['results'] ?? [];
        $lower = mb_strtolower($name);
        $match = null;
        foreach ([static fn (array $t) => mb_strtolower((string) ($t['name'] ?? '')) === $lower, static fn (array $t) => mb_strtolower((string) ($t['matched_term'] ?? '')) === $lower] as $exact) {
            foreach ($results as $taxon) {
                if (null === $match && \is_array($taxon) && isset($taxon['id']) && $exact($taxon)) {
                    $match = $taxon;
                }
            }
        }
        if (null === $match) {
            return null;
        }
        $taxon = $this->taxon((string) $match['id']);
        if (null === $taxon || mb_strtolower($taxon->label) === $lower || \in_array($lower, array_map('mb_strtolower', $taxon->synonyms), true)) {
            return $taxon;
        }

        return $taxon->with(['synonyms' => [...$taxon->synonyms, $name]]);
    }

    /** @param array<string, mixed> $t a taxon of version 1, whole or a search's result */
    private function toTaxon(array $t): Entity
    {
        $id = (string) $t['id'];
        $name = self::text($t['name'] ?? null) ?? $id;
        $rank = self::text($t['rank'] ?? null);
        $common = self::text($t['preferred_common_name'] ?? null);

        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'inaturalist');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('common_name', 'Common name', $common);
        $add('iconic_taxon', 'Iconic group', self::text($t['iconic_taxon_name'] ?? null));
        $lineage = [];
        foreach ($t['ancestors'] ?? [] as $ancestor) {
            $ancestorName = self::text($ancestor['name'] ?? null);
            $ancestorRank = (string) ($ancestor['rank'] ?? '');
            // "Life", iNaturalist's root, is no rank of the tree.
            if (null === $ancestorName || 'stateofmatter' === $ancestorRank) {
                continue;
            }
            $lineage[] = $ancestorName;
            if (\in_array($ancestorRank, self::RANKS, true)) {
                $add($ancestorRank, ucfirst($ancestorRank), $ancestorName);
            }
        }
        $add('lineage', 'Lineage', $lineage ? implode(' > ', $lineage) : null);
        $status = $t['conservation_status'] ?? null;
        if (\is_array($status) && null !== ($code = self::text($status['status'] ?? null))) {
            $authority = self::text($status['authority'] ?? null);
            $code = strtoupper($code);
            if (null !== $authority && str_contains($authority, 'IUCN') && isset(self::IUCN[$code])) {
                $add('iucn_status', 'IUCN Red List category', self::IUCN[$code].' ('.$code.')');
            } else {
                $add('conservation_status', 'Conservation status', $code.(null !== $authority ? ' ('.$authority.')' : ''));
            }
        }
        $add('extinct', 'Extinct', isset($t['extinct']) ? (bool) $t['extinct'] : null);
        $add('active', 'Active (a taxon iNaturalist keeps)', isset($t['is_active']) ? (bool) $t['is_active'] : null);
        $add('observations_count', 'Observations (on iNaturalist)', is_numeric($t['observations_count'] ?? null) ? (int) $t['observations_count'] : null);
        $add('wikipedia_url', 'Wikipedia', self::text($t['wikipedia_url'] ?? null));
        $photo = \is_array($t['default_photo'] ?? null) ? $t['default_photo'] : [];
        $image = self::text($photo['medium_url'] ?? null) ?? self::sized($photo['url'] ?? null);
        $add('image_license', 'Licence (of the image)', null === $image ? null : (self::text($photo['license_code'] ?? null) ?? 'all rights reserved'));
        $add('image_attribution', 'Attribution (of the image)', null === $image ? null : self::text($photo['attribution'] ?? null));

        $accepted = [];
        foreach ((false === ($t['is_active'] ?? true) ? $t['current_synonymous_taxon_ids'] ?? [] : []) as $current) {
            $accepted[] = Identifier::tryOf(Scheme::INATURALIST, $current);
        }

        return new Entity(
            id: Identifier::of(Scheme::INATURALIST, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: null === $common || mb_strtolower($common) === mb_strtolower($name) ? [] : [$common],
            description: self::text($t['wikipedia_summary'] ?? null),
            properties: $properties,
            url: 'https://www.inaturalist.org/taxa/'.$id,
            image: $image,
            source: 'inaturalist',
            related: array_filter([
                'parent' => $accepted ? null : Identifiers::of(Identifier::tryOf(Scheme::INATURALIST, $t['parent_id'] ?? null)),
                'accepted' => $accepted ? new Identifiers($accepted) : null,
            ], static fn (?Identifiers $ids) => null !== $ids && \count($ids) > 0),
        );
    }

    /**
     * An observation of version 2: what was seen, where and when, by whom,
     * how sure, under which licence.
     *
     * @param array<string, mixed> $o
     */
    private function toObservation(array $o): Entity
    {
        $id = (string) $o['id'];
        $taxon = \is_array($o['taxon'] ?? null) ? $o['taxon'] : [];
        $name = self::text($taxon['name'] ?? null) ?? self::text($o['species_guess'] ?? null) ?? 'Unknown';
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'inaturalist');
            }
        };
        $add('taxon_name', 'Taxon name', self::text($taxon['name'] ?? null));
        $add('taxon_rank', 'Taxon rank', self::text($taxon['rank'] ?? null));
        $add('common_name', 'Common name', self::text($taxon['preferred_common_name'] ?? null));
        $add('iconic_taxon', 'Iconic group', self::text($taxon['iconic_taxon_name'] ?? null));
        $add('species_guess', 'What the observer called it', self::text($o['species_guess'] ?? null));
        $add('quality_grade', 'Quality grade', self::text($o['quality_grade'] ?? null));
        $add('observed_on', 'Day observed', self::text($o['observed_on'] ?? null));
        $add('locality', 'Locality', self::text($o['place_guess'] ?? null));
        $obscured = (bool) ($o['obscured'] ?? false);
        $add('coordinates_obscured', 'Coordinates obscured (the place shown is not the place seen)', $obscured);
        $accuracy = $obscured ? ($o['public_positional_accuracy'] ?? $o['positional_accuracy'] ?? null) : ($o['positional_accuracy'] ?? null);
        $add('coordinate_uncertainty', 'Coordinate uncertainty', is_numeric($accuracy) ? new Quantity((int) $accuracy, 'm') : null);
        $add('geoprivacy', 'Geoprivacy', self::text($o['geoprivacy'] ?? null) ?? self::text($o['taxon_geoprivacy'] ?? null));
        $add('captive', 'Captive or cultivated', isset($o['captive']) ? (bool) $o['captive'] : null);
        $add('identification_agreements', 'Identifications agreeing', is_numeric($o['num_identification_agreements'] ?? null) ? (int) $o['num_identification_agreements'] : null);
        $add('identification_disagreements', 'Identifications disagreeing', is_numeric($o['num_identification_disagreements'] ?? null) ? (int) $o['num_identification_disagreements'] : null);
        $user = \is_array($o['user'] ?? null) ? $o['user'] : [];
        $add('recorded_by', 'Recorded by', self::text($user['name'] ?? null) ?? self::text($user['login'] ?? null));
        $add('license', 'Licence', self::text($o['license_code'] ?? null) ?? 'all rights reserved');
        $add('description', 'Notes', self::text($o['description'] ?? null));
        $photos = array_values(array_filter($o['photos'] ?? [], 'is_array'));
        $add('photo_count', 'Photos', $photos ? \count($photos) : null);
        $add('sound_count', 'Sounds', !empty($o['sounds']) && \is_array($o['sounds']) ? \count($o['sounds']) : null);
        $image = self::sized($photos[0]['url'] ?? null);
        $add('image_license', 'Licence (of the image)', null === $image ? null : (self::text($photos[0]['license_code'] ?? null) ?? 'all rights reserved'));
        $add('image_attribution', 'Attribution (of the image)', null === $image ? null : self::text($photos[0]['attribution'] ?? null));

        $place = null;
        if (\is_string($o['location'] ?? null) && preg_match('~^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*$~', $o['location'], $m)) {
            $place = new Point((float) $m[1], (float) $m[2]);
        }
        $day = self::text($o['observed_on'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::INATURALIST_OBSERVATION, $id),
            label: $name.' - '.implode(', ', array_filter([self::text($o['place_guess'] ?? null), $day])),
            domain: Domain::TAXONOMY,
            kind: 'observation',
            properties: $properties,
            url: 'https://www.inaturalist.org/observations/'.$id,
            image: $image,
            time: self::when($o),
            place: $place,
            source: 'inaturalist',
            related: array_filter(['taxon' => Identifiers::of(Identifier::tryOf(Scheme::INATURALIST, $taxon['id'] ?? null))], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /**
     * When it was seen: its time with its offset; a day without a time, the
     * day's midnight in the observation's time zone (UTC when it has none).
     *
     * @param array<string, mixed> $o
     */
    private static function when(array $o): ?\DateTimeImmutable
    {
        if (null !== ($time = self::time(\is_string($o['time_observed_at'] ?? null) ? $o['time_observed_at'] : null))) {
            return $time;
        }
        if (!\is_string($o['observed_on'] ?? null) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $o['observed_on'])) {
            return null;
        }
        try {
            $zone = new \DateTimeZone(\is_string($o['observed_time_zone'] ?? null) && '' !== $o['observed_time_zone'] ? $o['observed_time_zone'] : 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }

        return new \DateTimeImmutable($o['observed_on'].' 00:00:00', $zone);
    }

    /** A photo's medium size (500 px): iNaturalist answers its square (75 px), whose name says its size. */
    private static function sized(mixed $url): ?string
    {
        return \is_string($url) && '' !== $url ? (string) preg_replace('~/square\.(jpe?g|png|gif)(\?.*)?$~i', '/medium.$1$2', $url) : null;
    }
}
