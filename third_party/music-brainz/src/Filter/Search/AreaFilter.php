<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching areas
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Area
 */
class AreaFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\AliasNameTrait;
    use \MusicBrainz\Filter\Property\AreaIdTrait;
    use \MusicBrainz\Filter\Property\AreaNameTrait;
    use \MusicBrainz\Filter\Property\AreaTypeTrait;
    use \MusicBrainz\Filter\Property\BeginDateTrait;
    use \MusicBrainz\Filter\Property\DisambiguationTrait;
    use \MusicBrainz\Filter\Property\EndDateTrait;
    use \MusicBrainz\Filter\Property\EndedTrait;
    use \MusicBrainz\Filter\Property\Iso3166CodeTrait;
    use \MusicBrainz\Filter\Property\Iso31661CodeTrait;
    use \MusicBrainz\Filter\Property\Iso31662CodeTrait;
    use \MusicBrainz\Filter\Property\Iso31663CodeTrait;
}
