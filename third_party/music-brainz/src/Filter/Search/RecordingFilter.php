<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

/**
 * A filter for searching recordings
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search#Place
 */
class RecordingFilter extends AbstractFilter
{
    use \MusicBrainz\Filter\Property\ArtistNameWithoutAccentsTrait;
    use \MusicBrainz\Filter\Property\ArtistIdTrait;
    use \MusicBrainz\Filter\Property\ArtistRealNameTrait;
    use \MusicBrainz\Filter\Property\CountryTrait;
    use \MusicBrainz\Filter\Property\CreditNameTrait;
    use \MusicBrainz\Filter\Property\DateTrait;
    use \MusicBrainz\Filter\Property\DisambiguationTrait;
    use \MusicBrainz\Filter\Property\IsrcTrait;
    use \MusicBrainz\Filter\Property\LengthTrait;
    use \MusicBrainz\Filter\Property\MediumNumberTrait;
    use \MusicBrainz\Filter\Property\NumberOfTracksOnMediumTrait;
    use \MusicBrainz\Filter\Property\NumberOfTracksOnReleaseTrait;
    use \MusicBrainz\Filter\Property\PrimaryTypeTrait;
    use \MusicBrainz\Filter\Property\QuantizedLengthTrait;
    use \MusicBrainz\Filter\Property\RecordingIdTrait;
    use \MusicBrainz\Filter\Property\RecordingNameWithAccentsTrait;
    use \MusicBrainz\Filter\Property\RecordingNameWithoutAccentsTrait;
    use \MusicBrainz\Filter\Property\ReleaseFormatTrait;
    use \MusicBrainz\Filter\Property\ReleaseGroupIdTrait;
    use \MusicBrainz\Filter\Property\ReleaseGroupTypeTrait;
    use \MusicBrainz\Filter\Property\ReleaseIdTrait;
    use \MusicBrainz\Filter\Property\ReleaseNameWithoutAccentsTrait;
    use \MusicBrainz\Filter\Property\ReleaseStatusTrait;
    use \MusicBrainz\Filter\Property\SecondaryTypeTrait;
    use \MusicBrainz\Filter\Property\TagNameTrait;
    use \MusicBrainz\Filter\Property\TrackDisplayNumberTrait;
    use \MusicBrainz\Filter\Property\TrackIdTrait;
    use \MusicBrainz\Filter\Property\TrackNumberTrait;
    use \MusicBrainz\Filter\Property\VideoTrait;

    /**
     * Returns the field name for the number of tracks in the medium.
     *
     * @return string
     */
    public static function numberOfTracksOnMedium(): string
    {
        return 'tracks';
    }

    /**
     * Returns the field name for the number of tracks on release as a whole.
     *
     * @return string
     */
    public static function numberOfTracksOnRelease(): string
    {
        return 'tracksrelease';
    }
}
