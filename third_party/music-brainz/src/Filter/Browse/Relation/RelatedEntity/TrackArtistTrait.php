<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for a track_artist.
 */
trait TrackArtistTrait
{
    /**
     * @see EntityType::TRACK_ARTIST
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related artist
     *
     * @return void
     */
    public function trackArtist(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::TRACK_ARTIST));
    }
}
