<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching artists
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Artist
 */
class ArtistFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\AliasNameTrait;
    use \MusicBrainz\Filter\Property\AreaNameTrait;
    use \MusicBrainz\Filter\Property\ArtistIdTrait;
    use \MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
    use \MusicBrainz\Filter\Property\ArtistNameWithAccentsTrait;
    use \MusicBrainz\Filter\Property\SortNameTrait;
    use \MusicBrainz\Filter\Property\ArtistTypeTrait;
    use \MusicBrainz\Filter\Property\BeginAreaNameTrait;
    use \MusicBrainz\Filter\Property\BeginDateTrait;
    use \MusicBrainz\Filter\Property\CountryTrait;
    use \MusicBrainz\Filter\Property\DisambiguationTrait;
    use \MusicBrainz\Filter\Property\EndAreaNameTrait;
    use \MusicBrainz\Filter\Property\EndDateTrait;
    use \MusicBrainz\Filter\Property\EndedTrait;
    use \MusicBrainz\Filter\Property\GenderTrait;
    use \MusicBrainz\Filter\Property\IpiCodeTrait;
    use \MusicBrainz\Filter\Property\TagNameTrait;
}
