<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\AliasNameTrait;
use MusicBrainz\Filter\Property\AreaNameTrait;
use MusicBrainz\Filter\Property\BeginDateTrait;
use MusicBrainz\Filter\Property\CountryTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\EndDateTrait;
use MusicBrainz\Filter\Property\EndedTrait;
use MusicBrainz\Filter\Property\IpiCodeTrait;
use MusicBrainz\Filter\Property\LabelCodeTrait;
use MusicBrainz\Filter\Property\LabelIdTrait;
use MusicBrainz\Filter\Property\LabelNameWithAccentsTrait;
use MusicBrainz\Filter\Property\LabelNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\LabelTypeTrait;
use MusicBrainz\Filter\Property\SortNameTrait;
use MusicBrainz\Filter\Property\TagNameTrait;

/**
 * A filter for searching labels
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Label
 */
class LabelFilter extends AbstractFilter
{
    use AliasNameTrait;
    use AreaNameTrait;
    use BeginDateTrait;
    use LabelCodeTrait;
    use DisambiguationTrait;
    use CountryTrait;
    use EndDateTrait;
    use EndedTrait;
    use IpiCodeTrait;
    use LabelIdTrait;
    use LabelNameWithAccentsTrait;
    use LabelNameWithoutAccentsTrait;
    use LabelTypeTrait;
    use SortNameTrait;
    use TagNameTrait;
}
