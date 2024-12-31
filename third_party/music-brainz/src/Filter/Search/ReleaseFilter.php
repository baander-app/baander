<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\ArtistIdTrait;
use MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\ArtistRealNameTrait;
use MusicBrainz\Filter\Property\BarcodeTrait;
use MusicBrainz\Filter\Property\CountryTrait;
use MusicBrainz\Filter\Property\CreditNameTrait;
use MusicBrainz\Filter\Property\DataQualityTrait;
use MusicBrainz\Filter\Property\DateTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\LabelIdTrait;
use MusicBrainz\Filter\Property\LabelNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\LanguageTrait;
use MusicBrainz\Filter\Property\NumberOfDiscIdsOnMediumTrait;
use MusicBrainz\Filter\Property\NumberOfDiscIdsTrait;
use MusicBrainz\Filter\Property\NumberOfMediumsTrait;
use MusicBrainz\Filter\Property\NumberOfTracksOnMediumTrait;
use MusicBrainz\Filter\Property\NumberOfTracksOnReleaseTrait;
use MusicBrainz\Filter\Property\PrimaryTypeTrait;
use MusicBrainz\Filter\Property\ReleaseFormatTrait;
use MusicBrainz\Filter\Property\ReleaseGroupIdTrait;
use MusicBrainz\Filter\Property\ReleaseIdTrait;
use MusicBrainz\Filter\Property\ReleaseNameWithAccentsTrait;
use MusicBrainz\Filter\Property\ReleaseNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\ReleaseStatusTrait;
use MusicBrainz\Filter\Property\ScriptTrait;
use MusicBrainz\Filter\Property\SecondaryTypeTrait;
use MusicBrainz\Filter\Property\TagNameTrait;

/**
 * A filter for searching releases
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Release
 */
class ReleaseFilter extends AbstractFilter
{
    use ArtistIdTrait;
    use ArtistNameWithoutAccentsTrait;
    use ArtistRealNameTrait;
    use BarcodeTrait;
    use CountryTrait;
    use CreditNameTrait;
    use DataQualityTrait;
    use DateTrait;
    use DisambiguationTrait;
    use LabelIdTrait;
    use LabelNameWithoutAccentsTrait;
    use LanguageTrait;
    use NumberOfDiscIdsOnMediumTrait;
    use NumberOfDiscIdsTrait;
    use NumberOfMediumsTrait;
    use NumberOfTracksOnMediumTrait;
    use NumberOfTracksOnReleaseTrait;
    use PrimaryTypeTrait;
    use ReleaseFormatTrait;
    use ReleaseGroupIdTrait;
    use ReleaseIdTrait;
    use ReleaseNameWithAccentsTrait;
    use ReleaseNameWithoutAccentsTrait;
    use ReleaseStatusTrait;
    use ScriptTrait;
    use SecondaryTypeTrait;
    use TagNameTrait;
}
