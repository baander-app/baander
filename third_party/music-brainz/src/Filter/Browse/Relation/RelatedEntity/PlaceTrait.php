<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type place.
 */
trait PlaceTrait
{
    /**
     * Defines a relation to place.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related place
     *
     * @return void
     */
    public function place(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::PLACE));
    }
}
