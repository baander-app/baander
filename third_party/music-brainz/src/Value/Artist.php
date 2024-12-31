<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\ArtistRelationListTrait;
use MusicBrainz\Value;

/**
 * An artist
 *
 * @see https://musicbrainz.org/doc/Artist
 */
class Artist implements Value
{
    use ArtistRelationListTrait;
    use Property\AliasesTrait;
    use Property\AreaTrait;
    use Property\ArtistNameTrait;
    use Property\ArtistTypeTrait;
    use Property\BeginAreaTrait;
    use Property\CountryTrait;
    use Property\DisambiguationTrait;
    use Property\EndAreaTrait;
    use Property\GenderTrait;
    use Property\IsnisTrait;
    use Property\IpisTrait;
    use Property\LifeSpanTrait;
    use Property\MBIDTrait;
    use Property\ReleasesTrait;
    use Property\ReleaseGroupsTrait;
    use Property\ScoreTrait;
    use Property\SortNameTrait;
    use Property\TagsTrait;

    /**
     * Constructs an artist.
     *
     * @param array $artist Array of information about an artist
     */
    public function __construct(array $artist = [])
    {
        $this->setRelationsFromArray($artist);
        $this->setAliasesFromArray($artist);
        $this->setAreaFromArray($artist);
        $this->setArtistNameFromArray($artist, 'name');
        $this->setArtistTypeFromArray($artist);
        $this->setBeginAreaFromArray($artist);
        $this->setCountryFromArray($artist);
        $this->setDisambiguationFromArray($artist);
        $this->setEndAreaFromArray($artist);
        $this->setGenderFromArray($artist);
        $this->setIpisFromArray($artist);
        $this->setIsnisFromArray($artist);
        $this->setLifeSpanFromArray($artist);
        $this->setMbidFromArray($artist);
        $this->setReleasesFromArray($artist);
        $this->setReleaseGroupsFromArray($artist);
        $this->setScoreFromArray($artist);
        $this->setSortNameFromArray($artist);
        $this->setTagsFromArray($artist);
    }

    /**
     * Returns the artist as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getArtistName();
    }
}
