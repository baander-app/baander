<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\BarcodeTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\DiscIdTrait;
use MusicBrainz\Filter\Property\NumberOfTracksOnMediumTrait;
use MusicBrainz\Filter\Property\TitleTrait;

/**
 * A filter for searching CD stubs
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#CDStubs
 */
class CdStubFilter extends AbstractFilter
{
    use ArtistNameWithoutAccentsTrait;
    use BarcodeTrait;
    use DisambiguationTrait;
    use DiscIdTrait;
    use TitleTrait;
    use NumberOfTracksOnMediumTrait;
}
