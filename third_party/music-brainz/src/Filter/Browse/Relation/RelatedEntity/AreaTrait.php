<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type area.
 */
trait AreaTrait
{
    /**
     * Defines a relation to an area.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related area
     *
     * @return void
     */
    public function area(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::AREA));
    }
}
