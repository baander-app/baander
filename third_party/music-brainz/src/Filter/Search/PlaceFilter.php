<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching places
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Place
 */
class PlaceFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\AddressTrait;
    use \MusicBrainz\Filter\Property\AliasNameTrait;
    use \MusicBrainz\Filter\Property\AreaNameTrait;
    use \MusicBrainz\Filter\Property\BeginDateTrait;
    use \MusicBrainz\Filter\Property\DisambiguationTrait;
    use \MusicBrainz\Filter\Property\EndDateTrait;
    use \MusicBrainz\Filter\Property\EndedTrait;
    use \MusicBrainz\Filter\Property\LatitudeTrait;
    use \MusicBrainz\Filter\Property\LongitudeTrait;
    use \MusicBrainz\Filter\Property\PlaceIdTrait;
    use \MusicBrainz\Filter\Property\PlaceTypeTrait;
}
