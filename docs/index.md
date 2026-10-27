# omnilab/inaturalist

The iNaturalist API (`https://api.inaturalist.org/`, versions 1 and 2) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): taxa by id or name - common
name, IUCN status, lineage, default photo and its licence - as entities; the observations of a
place and a period, research grade by default, each with its licence, as occurrences. No key.

## Installation

```sh
composer require omnilab/inaturalist
```

```php
use Omnilab\INaturalist\INaturalistSourceFactory;

$inaturalist = (new INaturalistSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'locale' => 'en',                  // optional: the common names' language
    'quality_grade' => 'research',     // optional
]);
```

With the Symfony bundle, the factory is registered as `inaturalist` (see the core's docs).

## Credentials

None for reading: the iNaturalist API takes no key. It asks for 60 calls a minute at most (it
throttles at 100) and 10,000 a day, and blocks without notice a use it does not recognise: give
`mailto`.

## Options

The factory's options, with the defaults it applies (`INaturalistSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `locale` | `string` | `en` | no | the language of the common names (and of a taxon's Wikipedia summary) |
| `quality_grade` | `string` | `research` | no | the observations answered when a query does not say: `research`, `needs_id`, `casual`, or `any` |
| `radius_km` | `float` | `10` | no | the circle around a `Point`, in kilometres, for `occurrences()` when the query gives no radius |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: iNaturalist blocks, without notice, a use it does not recognise |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `1` | no | seconds left between two calls: iNaturalist asks for 60 a minute at most |
| `base_uri` | `string` | `https://api.inaturalist.org/` | no | the API the source calls |

Per call, `occurrences()` reads the filters `OccurrenceQuery::TAXON`, `OccurrenceQuery::LICENSE`
and `INaturalistSource::QUALITY_GRADE`; any other key is refused before any call
([Occurrences](#occurrences)).

## Capabilities

`capabilities()` answers `entity`, `search`, `occurrences`, `area`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a taxon id (`41964`, `inaturalist:`, its page) or a name, or an observation (`inaturalist.observation:`); another scheme refused before any call |
| `search` | yes | iNaturalist's taxa by name, 200 a page at most, 10,000 deep, with a `total` |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | yes | the placed observations of a box, a circle or the world, research grade by default, by taxon and licence; unsorted pages without a depth limit, sorted ones 10,000 deep; 200 a page at most |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: iNaturalist gives no identifier of another base for a taxon; an observation is published to GBIF under its own id |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | yes | a `BBox` (across the antimeridian too) or a `Point` and a radius |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The iNaturalist API, `https://api.inaturalist.org/`:

- **version 1** for the taxa: `v1/taxa/{id}?locale=` gives a taxon whole (its ancestors, its
  conservation statuses, its photos, its Wikipedia summary) in one call, 71 kB for the lion;
  `v1/taxa?q=` searches the names, scientific, common and former;
- **version 2** (2.2.0) for the observations: `v2/observations?fields=...` answers only the fields
  asked for. Version 1 has no such choice: on 2026-10-15 a page of 200 lion observations near
  Skukuza weighed 44,276,844 bytes there (5.3 s), and 182,716 in version 2 (1.1 s). Version 2 reads
  an observation by its number as a filter (`?id=`), its path taking a UUID only.

## Calls

| Method | Reads | iNaturalist |
|---|---|---|
| `entity()` | a taxon id: `41964`, `inaturalist:41964`, `inaturalist.taxon:41964`, `https://www.inaturalist.org/taxa/41964-Panthera-leo` | `v1/taxa/41964` |
| | a name matched exactly, scientific first, then a synonym or a common name (`Panthera leo`, `Felis leo`, `Lion`) | `v1/taxa?q=&is_active=true&per_page=30`, then `v1/taxa/{id}` |
| | an observation: `inaturalist.observation:332912788`, `https://www.inaturalist.org/observations/332912788` | `v2/observations?id=332912788&fields=` |
| `search()` | `Query::$text`, iNaturalist's order, active taxa only, page by number (10,000 deep); a domain other than taxonomy answers nothing | `v1/taxa?q=&is_active=true&per_page=&page=` |
| `occurrences()` | an `OccurrenceQuery` (below) | `v2/observations?...&geo=true&fields=` |

An identifier of another scheme (a GBIF key, an NCBI taxon, a Wikidata item, a GBIF occurrence) is
refused before any call. An unknown taxon or observation is null (iNaturalist answers an empty
list).

## What a taxon holds

`id`, `label` (its scientific name), `domain` taxonomy, `kind` (its rank), `synonyms` (its common
name; the name it was matched by), `description` (its Wikipedia summary, in the locale's language
when there is one), `url` (its iNaturalist page), `image` (its default photo, the 500-pixel size),
and:

| Key | iNaturalist | Value |
|---|---|---|
| `taxon_name`, `taxon_rank` | name, rank | text |
| `common_name` | preferred_common_name, in the `locale` | text |
| `iconic_taxon` | iconic_taxon_name: Mammalia, Aves, Plantae, Fungi... | text |
| `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the ancestors at those ranks (GBIF's and NCBI's keys) | text |
| `lineage` | every ancestor, the root ("Life") left out | text |
| `iucn_status` | the global conservation status when the IUCN's: `Vulnerable (VU)`, as GBIF writes it | text |
| `conservation_status` | the global status of another authority | text |
| `extinct`, `active` | extinct, is_active | boolean |
| `observations_count` | observations_count | number |
| `wikipedia_url` | wikipedia_url | text |
| `image_license`, `image_attribution` | the default photo's licence code and attribution | text |

`identifiers`: none but its own - iNaturalist's API gives no other base's id. `related`: `parent`, or
for an inactive taxon `accepted`, the taxa that replaced it. A search result holds the same, but
its lineage (a result has no ancestors).

## Occurrences

| `OccurrenceQuery` | iNaturalist |
|---|---|
| `place`: a `BBox` | `swlat`, `swlng`, `nelat`, `nelng`; a box across the antimeridian as it is: iNaturalist reads a west east of the east as one |
| `place`: a `Point` and `radius` | `lat`, `lng`, `radius` (km); `radius_km` (10) when the query gives none |
| `place`: none | the world |
| `from`, `to` | `d1`, `d2`: a bound at midnight as its day (the whole day), any other as the instant with its offset (iNaturalist compares to the second) |
| `OccurrenceQuery::TAXON` | an id or an `Identifier` of `inaturalist`: `taxon_id`, which takes its subordinate taxa; anything else, a name: `taxon_name` |
| `OccurrenceQuery::LICENSE` | `cc0`, `cc-by`, `cc-by-nc`, `cc-by-sa`, `cc-by-nd`, `cc-by-nc-sa`, `cc-by-nc-nd`, or a list: the observation's licence |
| `INaturalistSource::QUALITY_GRADE` (`quality_grade`) | `research` (the default: the `quality_grade` option), `needs_id`, `casual`, a list, or `any` |
| `sort`: none | `order_by=id&order=desc`: the latest added first, the next page `id_below` the last one (no depth limit) |
| `sort`: `NEWEST`, `OLDEST` | `order_by=observed_on`, the next page by number, 10,000 deep (iNaturalist's error beyond: refused before) |
| `limit` | `per_page`, 200 at most |

Always asked: `geo=true` (placed only). A research-grade observation is dated and placed by
definition; a casual one may have no day, and is then left out of the page. The total is
iNaturalist's count on the first page; a later page of the unsorted order has none (iNaturalist
counts what is left below the cursor), a page of a sorted order has it. Any other filter
(`COUNTRY`: iNaturalist knows places by its own ids, not ISO codes; a magnitude) or sort is refused
before any call.

An observation holds: `id` (`inaturalist.observation:407894559`), `label` (the taxon, the place
guessed, the day: `Panthera leo melanochaita - South Africa, 2025-05-20`), `domain` taxonomy, `kind`
`observation`, `time` (when seen, at its offset; a day without a time, its midnight in the
observation's time zone), `place` (its coordinates - for a threatened taxon, iNaturalist's public,
obscured ones), `url`, `image` (its first photo, 500 px), `related` (`taxon`: what the community
identified it as), and:

| Key | iNaturalist | Value |
|---|---|---|
| `taxon_name`, `taxon_rank`, `common_name`, `iconic_taxon` | its taxon's | text |
| `species_guess` | what the observer called it | text |
| `quality_grade` | research, needs_id, casual | text |
| `observed_on`, `locality` | the day, place_guess | text |
| `coordinates_obscured`, `geoprivacy` | obscured, geoprivacy or the taxon's | boolean, text |
| `coordinate_uncertainty` | the public positional accuracy when obscured, else the observer's | Quantity, m |
| `captive` | captive or cultivated | boolean |
| `identification_agreements`, `identification_disagreements` | the community's | number |
| `recorded_by` | the observer's name, else login | text |
| `license` | license_code, or `all rights reserved` when none | text |
| `description` | the observer's notes | text |
| `photo_count`, `sound_count` | | number |
| `image_license`, `image_attribution` | the first photo's | text |

A page holds what `entity()` gives for it: one call either way.

## What iNaturalist does not give here

- No identifier of another base for a taxon (no GBIF key, NCBI id or Wikidata item in its API):
  Wikidata's P3151 is the bridge, which the `Collector` crosses.
- Not read: the observations' identifications one by one, comments, annotations, observation
  fields and projects; the taxa's other photos, regional conservation statuses, listed taxa and
  range maps; the places (iNaturalist's own ids); anything that needs an account (hidden
  coordinates, writing).

## The Collector

Answered on 2026-10-15 (the core's `docs/merging.md` gives them whole):

- `collect('wikidata', 'Q140', 1, ['inaturalist'])`: the lion's item carries P3151 = 41964; three
  calls (the item, its class's label, the taxon); its IUCN category and common name from
  iNaturalist.
- `collect('gbif', '4CGXP', 2, ['ncbi', 'wikidata', 'inaturalist'])`: GBIF, then NCBI Taxonomy (9689)
  and Wikidata (Q140 by the legacy number, P846), then iNaturalist by Wikidata's P3151; nine calls;
  `iucn_status` from GBIF and iNaturalist alike (Vulnerable), three common names (African Lion,
  lion, Lion).
- back: `collect('inaturalist', '41964', 2, ['wikidata', 'gbif', 'ncbi'])`: Wikidata finds the item
  by P3151, then GBIF and NCBI by its identifiers; nine calls.
- one occurrence: GBIF's `gbif.occurrence:5007681734` is the iNaturalist observation 257284120
  (omnilab/gbif reads the observation's page in its `occurrenceID`), so
  `$collector->follow($occurrence, 1, ['inaturalist'])` reads it here in one call - its quality
  grade and identifications beside GBIF's fields.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\INaturalist\INaturalistSourceFactory;
use Omnilab\Model\Point;
use Omnilab\Source\OccurrenceQuery;
use Symfony\Component\HttpClient\HttpClient;

$inaturalist = (new INaturalistSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'locale' => 'fr']);

$lion = $inaturalist->entity('Panthera leo');
echo $lion->label, ' (', $lion->id, '): ', $lion->value('common_name'), ', ', $lion->value('iucn_status'), ', ', $lion->value('observations_count'), " observations\n";
echo '  photo ', $lion->image, ' (', $lion->value('image_license'), ")\n";

// The research-grade lions within 100 km of Skukuza in October 2026, under an open licence.
$page = $inaturalist->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-26'), [OccurrenceQuery::TAXON => 'Panthera leo', OccurrenceQuery::LICENSE => ['cc0', 'cc-by', 'cc-by-nc']], radius: 100, limit: 3));
printf("%d observations; the latest added %d:\n", $page->total, count($page));
foreach ($page as $observation) {
    echo '  ', $observation->time->format('Y-m-d'), '  ', $observation->label, '  ', $observation->value('license'), "\n";
}
```

```
Panthera leo (inaturalist:41964): Lion, Vulnerable (VU), 25789 observations
  photo https://inaturalist-open-data.s3.amazonaws.com/photos/9225318/medium.jpg (cc-by-nc-nd)
12 observations; the latest added 3:
  2026-10-26  Panthera leo melanochaita - Ehlanzeni, ZA-MP, ZA, 2026-10-26  cc-by-nc
  2026-10-24  Panthera leo melanochaita - Ehlanzeni, ZA-MP, ZA, 2026-10-24  cc-by
  2026-10-18  Panthera leo melanochaita - Ehlanzeni, ZA-MP, ZA, 2026-10-18  cc-by-nc
```

(as answered on 2026-10-27: three calls, a second apart; the locale `fr` gave the lion's common name as iNaturalist holds it)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        inaturalist: { factory: inaturalist, options: { mailto: '%env(OMNILAB_MAILTO)%', locale: en, quality_grade: research } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call (`NotSupportedException`): an identifier of another scheme, a filter or a sort not
read, a taxon of another scheme, a page past 10,000 deep of a search or a sorted query; a quality
grade or a licence iNaturalist does not have, a cursor that is not this source's
(`\InvalidArgumentException`). iNaturalist's errors (`error` in version 1, `errors` in version 2)
are read into the `ProviderException`'s message.

## Verified, and not

Against the real service on 2026-10-15, without a key: the lion by its id, its page, its name, its
synonym *Felis leo* and its common name; its lineage, IUCN category, default photo and licence, in
English and in French; a search ("Panthera leo", 9 taxa, two pages of three); the research-grade
lion observations within 100 km of Skukuza in 2025, all 504 in three pages of 200 (every one in
2025 and within the circle; 340 under CC BY-NC, 74 all rights reserved, 61 CC BY, 26 CC0, 3 CC
BY-NC-ND), the latest observed first, the next page by id; 98 under CC0 in the world that year,
679 casual ones, 3,518 of any grade; five between 08:00 and 12:00 (+02:00) on 2025-06-01; 298 in
January 2025 in a box across the antimeridian (170° to -170°, 20° S to 15° S), as many as its
two halves (256 and 42); 28 within 10 km of Strasbourg from 2026-10-01 to 10-07; an observation
by its number and an unknown one; iNaturalist's error past 10,000 deep. The fixtures in
`Tests/Fixtures/` are its answers.

Again on 2026-10-27: the lion by its name (in French), its openly licensed observations within
100 km of Skukuza in October 2026 (twelve; the example above).

Not verified: iNaturalist's 429 and its blocks (none met: the handling of a 429 is the core's); an
inactive taxon (`accepted`); a casual observation without a day.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by iNaturalist. Its API is subject to iNaturalist's Terms of Service and is "intended to
support application development, not data scraping"; each observation and photo keeps its owner's
licence and attribution; for bulk data iNaturalist points to its exports and GBIF, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-inaturalist#terms-of-use) says.
Whoever runs the package is responsible for complying with them and for any use made of what it
returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-inaturalist#responsibility) section.
