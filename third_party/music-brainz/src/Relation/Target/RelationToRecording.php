<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\Data\RecordingData;
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

    /**
     * Sets the related recording.
     *
     * @param mixed $recording Information about the related recording
     *
     * @return void
     */
    protected function setRelatedEntity(mixed $recording): void
    {
        $this->recording = new Recording($recording);
    }

    public function __construct(
        private Recording $recording
    ) {}
}
