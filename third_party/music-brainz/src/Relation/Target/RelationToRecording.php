<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Recording;

/**
 * A recording relation
 */
class RelationToRecording extends Relation
{
    /**
     * The related recording
     *
     * @var Recording
     */
    private $recording;

    /**
     * Sets the related recording.
     *
     * @param array $recording Information about the related recording
     *
     * @return void
     */
    protected function setRelatedEntity(array $recording): void
    {
        $this->recording = new Recording($recording);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RECORDING);
    }

    /**
     * Generates the related recording.
     *
     * @return Recording
     */
    public function getRecording(): Recording
    {
        return $this->recording;
    }
}
