<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\AliasNameTrait;
use MusicBrainz\Filter\Property\AreaIdTrait;
use MusicBrainz\Filter\Property\AreaNameTrait;
use MusicBrainz\Filter\Property\AreaTypeTrait;
use MusicBrainz\Filter\Property\BeginDateTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\EndDateTrait;
use MusicBrainz\Filter\Property\EndedTrait;
use MusicBrainz\Filter\Property\Iso31661CodeTrait;
use MusicBrainz\Filter\Property\Iso31662CodeTrait;
use MusicBrainz\Filter\Property\Iso31663CodeTrait;
use MusicBrainz\Filter\Property\Iso3166CodeTrait;

/**
 * A filter for searching areas
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Area
 */
class AreaFilter extends AbstractFilter
{
    use AliasNameTrait;
    use AreaIdTrait;
    use AreaNameTrait;
    use AreaTypeTrait;
    use BeginDateTrait;
    use DisambiguationTrait;
    use EndDateTrait;
    use EndedTrait;
    use Iso3166CodeTrait;
    use Iso31661CodeTrait;
    use Iso31662CodeTrait;
    use Iso31663CodeTrait;
}
