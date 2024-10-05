<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type work.
 */
trait WorkTrait
{
    /**
     * Defines a relation to a work.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related work
     *
     * @return void
     */
    public function work(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::WORK));
    }
}
