<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type artist.
 */
trait ArtistTrait
{
    /**
     * Defines a relation to an artist.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related artist
     *
     * @return void
     */
    public function artist(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::ARTIST));
    }
}
