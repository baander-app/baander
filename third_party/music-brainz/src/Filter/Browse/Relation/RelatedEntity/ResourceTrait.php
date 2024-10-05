<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type recource.
 */
trait ResourceTrait
{
    /**
     * Defines a relation to a resource.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related resource
     *
     * @return void
     */
    public function resource(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::RESOURCE));
    }
}
