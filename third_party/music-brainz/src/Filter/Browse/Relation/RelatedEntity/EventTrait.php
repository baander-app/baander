<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type event.
 */
trait EventTrait
{
    /**
     * SDefines a relation to an event.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related event
     *
     * @return void
     */
    public function event(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::EVENT));
    }
}
