<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type label.
 */
trait LabelTrait
{
    /**
     * Defines a relation to a label.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related label
     *
     * @return void
     */
    public function label(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::LABEL));
    }
}
