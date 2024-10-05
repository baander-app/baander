<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type collection.
 */
trait CollectionTrait
{
    /**
     * Defines a relation to a collection.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related collection
     *
     * @return void
     */
    public function collection(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::COLLECTION));
    }
}
