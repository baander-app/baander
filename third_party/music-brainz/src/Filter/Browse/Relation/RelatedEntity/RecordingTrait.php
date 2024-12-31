<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Browse\Relation\RelatedEntity;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;

/**
 * Provides a setter for the entity type recording.
 */
trait RecordingTrait
{
    /**
     * Defines a relation to a recording.
     *
     * @param MBID $mbid The MusicBrainz Identifier (MBID) of the related recording
     *
     * @return void
     */
    public function recording(MBID $mbid): void
    {
        $this->setEntityId($mbid);
        $this->setEntityType(new EntityType(EntityType::RECORDING));
    }
}
