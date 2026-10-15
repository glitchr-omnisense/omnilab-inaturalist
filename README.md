# omnilab/inaturalist

iNaturalist for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a taxon by its id or a
name iNaturalist matches - its rank, common name in a language, iconic group, IUCN Red List
category, lineage, Wikipedia summary, number of observations, its default photo with its licence
and attribution; its parent as a related thing; a search of its names. And the observations of a
place and a period - research grade by default -, each an entity with its time and its place, its
taxon related, its licence and its photo's, in the core's fourth shape (occurrences). No key.

```php
$inaturalist = (new INaturalistSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$lion = $inaturalist->entity('41964');           // or 'inaturalist:41964', its page, 'Panthera leo', 'Felis leo'
$lion->label;                                    // Panthera leo
$lion->value('common_name');                     // Lion
$lion->value('iucn_status');                     // Vulnerable (VU)
$lion->image;                                    // its default photo, 500 px
$lion->value('image_license');                   // cc-by-nc-nd: show its attribution with it
$lion->related('parent')->keys();                // inaturalist:41962 (Panthera)

// The research-grade lion observations within 100 km of Skukuza (Kruger National Park) in 2025.
$query = new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'),
    new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '41964'], radius: 100, limit: 200);
$page = $inaturalist->occurrences($query);
$page->total;                                    // 504
$page->first()->related('taxon')->keys();        // inaturalist:557401: the subspecies, related
$page->first()->value('license');                // cc-by-nc: each observation's own
$inaturalist->occurrences($query->with(['cursor' => $page->next]));   // the next 200
```

```yaml
omnilab:
    sources:
        inaturalist:
            factory: inaturalist
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                locale: fr                     # the common names' language
                quality_grade: research        # research, needs_id, casual, or any
                radius_km: 10                  # the circle around a Point, for occurrences(), when a query gives none
```

iNaturalist gives no identifier of another base: the way between it and the others is Wikidata,
whose items carry the iNaturalist taxon (P3151). With GBIF and Wikidata configured,
`$collector->collect('gbif', '4CGXP', 2)` reaches iNaturalist through Wikidata, and
`collect('inaturalist', '41964', 2)` reaches Wikidata, then GBIF and NCBI. A GBIF occurrence that is
an iNaturalist observation carries its number: `$collector->follow($occurrence)` reads it here.

## Which API

Version 1 for the taxa (`/v1/taxa`: a taxon whole, ancestors and conservation statuses, in one
call); **version 2** for the observations (`/v2/observations`), which answers only the fields
asked for: a page of 200 lion observations weighs 44 MB in version 1 (every identification, comment
and vote of each), 0.18 MB so.

## Terms of use

The API is free and needs no key for reading; it is subject to iNaturalist's Terms of Service, is
"intended to support application development, not data scraping", and iNaturalist blocks without
notice a use that weighs on it. It throttles at 100 calls a minute and asks for 60 at most and
10,000 a day: the source leaves one second between two calls. Each observation and each photo
keeps its owner's licence (`license`, `image_license`: CC0, CC BY, CC BY-NC..., or all rights
reserved), and its attribution must go with it; the `license` filter asks for the openly licensed
only. For bulk data, iNaturalist points to its exports and to GBIF (research-grade observations
under CC0, CC BY and CC BY-NC go there), not to this API.

See [docs/](docs/index.md) for what is read, how it maps, and what iNaturalist does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by iNaturalist and the observers who own its records. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
