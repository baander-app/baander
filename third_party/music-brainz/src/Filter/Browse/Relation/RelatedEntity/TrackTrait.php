<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type track.
 */
trait TrackTrait
{
    /**
     * Defines a relation to a track.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related track
     *
     * @return void
     */
    public function track(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::TRACK));
    }
}
