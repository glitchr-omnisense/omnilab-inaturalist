<?php

namespace Omnilab\INaturalist;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * iNaturalist: taxa by id or name, their rank, common name, conservation
 * status, lineage and default photo with its licence; and the observations
 * of a place and a period, research grade by default - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: iNaturalist blocks, without notice, a use it does not recognise
 *     locale: 'en'                     # the language of the common names (and of a taxon's Wikipedia summary)
 *     quality_grade: 'research'        # the observations answered when a query does not say: research, needs_id, casual, or any
 *     radius_km: 10                    # the circle around a Point, for occurrences() when the query gives none
 *     throttle: 1.0                    # seconds between two calls: iNaturalist asks for 60 a minute at most (it blocks beyond 100), 10,000 a day
 *     base_uri: 'https://api.inaturalist.org/'
 */
final class INaturalistSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'inaturalist',
            'omnilab.required_options' => [],
            'locale' => 'en',
            'quality_grade' => 'research',
            'radius_km' => 10.0,
            'throttle' => INaturalistSource::THROTTLE,
            'base_uri' => INaturalistSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new INaturalistSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('locale'),
            (string) $c->get('quality_grade'),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', INaturalistSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
