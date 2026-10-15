<?php

namespace Omnilab\INaturalist\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\INaturalist\INaturalistSource;
use Omnilab\INaturalist\INaturalistSourceFactory;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.inaturalist.org on 2026-10-15: the lion's taxon
 * (41964) in version 1, an unknown taxon, the taxa matching "Felis leo"
 * (30) and "Panthera leo" (two pages of three); in version 2, asked for the
 * source's fields, the research-grade lion observations within 100 km of
 * Skukuza (-24.99, 31.59) in 2025 - the latest added first, two pages of
 * three by id, and the latest observed first -, one observation by its
 * number (332912788) and an unknown one.
 */
final class INaturalistSourceTest extends TestCase
{
    /** @var list<string> the URLs asked, decoded, without the fields */
    private array $urls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = ltrim((string) parse_url($url, \PHP_URL_PATH), '/');
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->urls[] = (string) preg_replace('~&fields=[^&]*~', '', rawurldecode($url));
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                'v1/taxa' === $path => match ($query['q'] ?? null) {
                    'Felis leo' => 'taxa-q-felis-leo.json',
                    'Panthera leo' => 'taxa-q-panthera-leo-'.($query['page'] ?? 1).'.json',
                    default => 'empty',
                },
                str_starts_with($path, 'v1/taxa/') => 'taxon-'.basename($path).'.json',
                'v2/observations' === $path && isset($query['id']) => 'observation-'.$query['id'].'.json',
                'v2/observations' === $path && 'observed_on' === ($query['order_by'] ?? null) => 'observations-skukuza-newest.json',
                'v2/observations' === $path && '-24.99' === ($query['lat'] ?? null) => 'observations-skukuza-'.(isset($query['id_below']) ? 2 : 1).'.json',
                default => 'empty',
            };

            return 'empty' === $fixture || !is_file(__DIR__.'/Fixtures/'.$fixture)
                ? new MockResponse('{"total_results":0,"page":1,"per_page":30,"results":[]}')
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        // No wait between the recorded answers; the throttle's default is checked apart.
        return (new INaturalistSourceFactory($http))->create($options + ['throttle' => 0.0]);
    }

    public function testTheLionByItsIdInOneCall(): void
    {
        $lion = $this->source()->entity('41964');

        self::assertSame(['https://api.inaturalist.org/v1/taxa/41964?locale=en'], $this->urls);
        self::assertSame('inaturalist:41964', $lion->id->key());
        self::assertSame(['Panthera leo', Domain::TAXONOMY, 'species', ['Lion']], [$lion->label, $lion->domain, $lion->kind, $lion->synonyms]);
        self::assertSame(['Panthera leo', 'species', 'Lion', 'Mammalia'], [$lion->value('taxon_name'), $lion->value('taxon_rank'), $lion->value('common_name'), $lion->value('iconic_taxon')]);
        self::assertSame(['Animalia', 'Chordata', 'Mammalia', 'Carnivora', 'Felidae', 'Panthera'], [$lion->value('kingdom'), $lion->value('phylum'), $lion->value('class'), $lion->value('order'), $lion->value('family'), $lion->value('genus')]);
        self::assertStringStartsWith('Animalia > Chordata > Vertebrata', $lion->value('lineage'));
        self::assertStringEndsWith('Felidae > Pantherinae > Panthera', $lion->value('lineage'));
        self::assertSame('Vulnerable (VU)', $lion->value('iucn_status'), 'the IUCN\'s global status, as GBIF writes it');
        self::assertSame([false, true, 25519], [$lion->value('extinct'), $lion->value('active'), $lion->value('observations_count')]);
        self::assertSame('https://inaturalist-open-data.s3.amazonaws.com/photos/9225318/medium.jpg', $lion->image);
        self::assertSame(['cc-by-nc-nd', '(c) seasav, some rights reserved (CC BY-NC-ND), uploaded by seasav'], [$lion->value('image_license'), $lion->value('image_attribution')]);
        self::assertStringStartsWith('The lion (Panthera leo) is a species in the family Felidae', $lion->description, 'its Wikipedia summary, tags stripped');
        self::assertSame('https://www.inaturalist.org/taxa/41964', $lion->url);
        self::assertSame('inaturalist', $lion->property('iucn_status')->source);
    }

    public function testNoIdentifierOfAnotherBaseItsParentRelated(): void
    {
        $lion = $this->source()->entity(Identifier::of(Scheme::INATURALIST, '41964'));

        self::assertSame(['inaturalist:41964'], $lion->identifiers()->keys(), 'iNaturalist gives none: Wikidata (P3151) leads to it');
        self::assertSame(['inaturalist:41962'], $lion->related('parent')->keys(), 'Panthera');
    }

    public function testTheSameLionByItsPageOrItsNames(): void
    {
        foreach (['inaturalist:41964', 'https://www.inaturalist.org/taxa/41964-Panthera-leo', 'Panthera leo', 'Felis leo'] as $id) {
            $this->urls = [];
            self::assertSame('inaturalist:41964', $this->source()->entity($id)?->id->key(), $id);
        }
        self::assertSame(['https://api.inaturalist.org/v1/taxa?q=Felis leo&is_active=true&per_page=30&locale=en', 'https://api.inaturalist.org/v1/taxa/41964?locale=en'], $this->urls, 'a name matched, then the taxon whole');
        self::assertContains('Felis leo', $this->source()->entity('Felis leo')->synonyms, 'the synonym it was matched by');
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('999999999'));
        self::assertNull($this->source()->entity('Panthera xyzzz'));
        self::assertNull($this->source()->entity('inaturalist.observation:1'));
        self::assertNull($this->source()->entity(''));
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::GBIF, '4CGXP'), Identifier::of(Scheme::NCBI_TAXON, '9689'), Identifier::wikidata('Q140'), 'Q140', 'gbif:4CGXP', Identifier::of(Scheme::GBIF_OCCURRENCE, '5007681734')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testSearchPageByPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('Panthera leo', limit: 3));

        self::assertSame(['inaturalist:41962', 'inaturalist:41964', 'inaturalist:557401'], array_map(static fn ($e) => $e->id->key(), $page->items), 'iNaturalist\'s order');
        self::assertSame(['Panthera', 'Panthera leo', 'Panthera leo melanochaita'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame([9, '2'], [$page->total, $page->next]);
        $second = $source->search(new Query('Panthera leo', limit: 3, cursor: $page->next));
        self::assertSame('https://api.inaturalist.org/v1/taxa?q=Panthera leo&is_active=true&per_page=3&page=2&locale=en', $this->urls[1]);
        self::assertSame('3', $second->next);
        self::assertSame([], $source->search(new Query('Panthera leo', Domain::CHEMISTRY))->items);
        self::assertCount(2, $this->urls);
    }

    public function testTheObservationsOfAPlaceAndAPeriodResearchGrade(): void
    {
        $page = $this->source()->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '41964'], radius: 100, limit: 3));

        self::assertSame(['https://api.inaturalist.org/v2/observations?taxon_id=41964&quality_grade=research&geo=true&d1=2025-01-01&d2=2025-12-31&lat=-24.99&lng=31.59&radius=100&order_by=id&order=desc&per_page=3&locale=en'], $this->urls, 'one call: research grade, placed, the latest added first');
        self::assertSame([3, 504, 'below:407163105'], [\count($page), $page->total, $page->next]);
        $lion = $page->first();
        self::assertSame('inaturalist.observation:407894559', $lion->id->key());
        self::assertSame('Panthera leo melanochaita - South Africa, 2025-05-20', $lion->label);
        self::assertSame([Domain::TAXONOMY, 'observation'], [$lion->domain, $lion->kind]);
        self::assertSame('2025-05-20T16:46:24+02:00', $lion->time->format(\DATE_ATOM));
        self::assertEquals(new Point(-24.1987699852, 31.3262411907), $lion->place);
        self::assertSame(['inaturalist:557401'], $lion->related('taxon')->keys(), 'what it is of: related');
        self::assertSame([], $lion->identifiers->keys());
        self::assertSame(['Panthera leo melanochaita', 'subspecies', 'Southern Lion', 'research', 'Daniel Wiberg', 'cc-by-nc'], [$lion->value('taxon_name'), $lion->value('taxon_rank'), $lion->value('common_name'), $lion->value('quality_grade'), $lion->value('recorded_by'), $lion->value('license')]);
        self::assertSame([true, '30112 m', 6], [$lion->value('coordinates_obscured'), (string) $lion->property('coordinate_uncertainty'), $lion->value('identification_agreements')], 'a threatened taxon: its place blurred, the public uncertainty');
        self::assertSame(['https://inaturalist-open-data.s3.amazonaws.com/photos/749673662/medium.jpg', 'cc-by-nc', '(c) Daniel Wiberg, some rights reserved (CC BY-NC)'], [$lion->image, $lion->value('image_license'), $lion->value('image_attribution')]);
        self::assertSame('https://www.inaturalist.org/observations/407894559', $lion->url);
        foreach ($page as $observation) {
            self::assertTrue(null !== $observation->time && '2025' === $observation->time->format('Y') && (new Point(-24.99, 31.59))->around(100)->contains($observation->place));
        }
    }

    public function testTheNextPageById(): void
    {
        $source = $this->source();
        $query = new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => Identifier::of(Scheme::INATURALIST, '41964')], radius: 100, limit: 3);
        $second = $source->occurrences($query->with(['cursor' => $source->occurrences($query)->next]));

        self::assertStringContainsString('&order_by=id&order=desc&id_below=407163105&per_page=3', $this->urls[1]);
        self::assertSame(['inaturalist.observation:406863098', 'inaturalist.observation:406863070', 'inaturalist.observation:406863049'], array_map(static fn ($o) => $o->id->key(), $second->items));
        self::assertSame(['below:406863049', null], [$second->next, $second->total], 'iNaturalist counts what is left: no total but the first page\'s');
    }

    public function testTheLatestObservedFirstPagedByNumber(): void
    {
        $page = $this->source()->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '41964'], radius: 100, limit: 3, sort: OccurrenceQuery::NEWEST));

        self::assertStringContainsString('&order_by=observed_on&order=desc&per_page=3', $this->urls[0]);
        self::assertSame([504, '2'], [$page->total, $page->next]);
        self::assertSame(['2025-12-31T19:09:57+02:00', '2025-12-31T07:44:16+02:00', '2025-12-30T19:03:06+02:00'], array_map(static fn ($o) => $o->time->format(\DATE_ATOM), $page->items));
    }

    public function testThePlaceThePeriodAndTheFiltersAsINaturalistTakesThem(): void
    {
        $source = $this->source(null, ['radius_km' => 25, 'locale' => 'fr']);
        $source->occurrences(new OccurrenceQuery(new BBox(170, -20, -170, -15), new \DateTimeImmutable('2025-06-01T08:00:00+02:00'), new \DateTimeImmutable('2025-06-01'), [OccurrenceQuery::TAXON => 'Panthera leo', OccurrenceQuery::LICENSE => ['CC0', 'cc-by'], INaturalistSource::QUALITY_GRADE => ['research', 'needs_id']], limit: 1000, sort: OccurrenceQuery::OLDEST));
        $source->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521), filters: [INaturalistSource::QUALITY_GRADE => 'any']));

        self::assertSame('https://api.inaturalist.org/v2/observations?taxon_name=Panthera leo&quality_grade=research,needs_id&license=cc0,cc-by&geo=true&d1=2025-06-01T08:00:00+02:00&d2=2025-06-01&swlat=-20&swlng=170&nelat=-15&nelng=-170&order_by=observed_on&order=asc&per_page=200&locale=fr', $this->urls[0], 'a name; an instant, a whole day; a box across the antimeridian as it is; 200 a page at most');
        self::assertSame('https://api.inaturalist.org/v2/observations?geo=true&lat=48.5734&lng=7.7521&radius=25&order_by=id&order=desc&per_page=25&locale=fr', $this->urls[1], 'any grade, whenever, the configured radius');
    }

    public function testWhatItCannotFilterOrSortByIsRefusedBeforeAnyCall(): void
    {
        $refused = 0;
        foreach ([
            new OccurrenceQuery(filters: [OccurrenceQuery::COUNTRY => 'ZA']),
            new OccurrenceQuery(filters: [OccurrenceQuery::MIN_MAGNITUDE => 4]),
            new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => Identifier::of(Scheme::GBIF, '4CGXP')]),
            new OccurrenceQuery(sort: 'magnitude'),
            new OccurrenceQuery(sort: OccurrenceQuery::NEWEST, limit: 200, cursor: '51'),
        ] as $query) {
            try {
                $this->source()->occurrences($query);
            } catch (NotSupportedException) {
                ++$refused;
            }
        }
        self::assertSame(5, $refused);
        foreach ([[OccurrenceQuery::LICENSE => 'gpl'], [INaturalistSource::QUALITY_GRADE => 'good']] as $filters) {
            try {
                $this->source()->occurrences(new OccurrenceQuery(filters: $filters));
                self::fail(json_encode($filters));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testAnObservationByItsNumberInOneCall(): void
    {
        $lion = $this->source()->entity('https://www.inaturalist.org/observations/332912788');

        self::assertCount(1, $this->urls);
        self::assertStringStartsWith('https://api.inaturalist.org/v2/observations?id=332912788', $this->urls[0]);
        self::assertSame(['inaturalist.observation:332912788', '2025-12-31T19:09:57+02:00', 'Panthera leo melanochaita - Ehlanzeni, ZA-MP, ZA, 2025-12-31'], [$lion?->id->key(), $lion?->time->format(\DATE_ATOM), $lion?->label]);
    }

    public function testCapabilitiesAndRateLimit(): void
    {
        $source = $this->source();
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES, Capability::AREA], $source->capabilities());
        self::assertSame(INaturalistSource::THROTTLE, (new \ReflectionProperty($source, 'throttle'))->getValue((new INaturalistSourceFactory(new MockHttpClient()))->create()), 'one call a second: iNaturalist\'s 60 a minute');

        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('41964');
    }
}
