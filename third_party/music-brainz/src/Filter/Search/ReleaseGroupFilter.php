<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use MusicBrainz\Filter\Property\ArtistIdTrait;
use MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\ArtistRealNameTrait;
use MusicBrainz\Filter\Property\CreditNameTrait;
use MusicBrainz\Filter\Property\DisambiguationTrait;
use MusicBrainz\Filter\Property\PrimaryTypeTrait;
use MusicBrainz\Filter\Property\ReleaseGroupIdTrait;
use MusicBrainz\Filter\Property\ReleaseGroupNameWithAccentsTrait;
use MusicBrainz\Filter\Property\ReleaseGroupNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\ReleaseGroupTypeTrait;
use MusicBrainz\Filter\Property\ReleaseIdTrait;
use MusicBrainz\Filter\Property\ReleaseNameWithoutAccentsTrait;
use MusicBrainz\Filter\Property\ReleaseStatusTrait;
use MusicBrainz\Filter\Property\SecondaryTypeTrait;
use MusicBrainz\Filter\Property\TagNameTrait;

/**
 * A filter for searching release groups
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Release_Group
 */
class ReleaseGroupFilter extends AbstractFilter
{
    use ArtistIdTrait;
    use ArtistNameWithoutAccentsTrait;
    use ArtistRealNameTrait;
    use DisambiguationTrait;
    use CreditNameTrait;
    use PrimaryTypeTrait;
    use ReleaseGroupIdTrait;
    use ReleaseGroupNameWithAccentsTrait;
    use ReleaseGroupNameWithoutAccentsTrait;
    use ReleaseGroupTypeTrait;
    use ReleaseIdTrait;
    use ReleaseNameWithoutAccentsTrait;
    use ReleaseStatusTrait;
    use SecondaryTypeTrait;
    use TagNameTrait;
}
