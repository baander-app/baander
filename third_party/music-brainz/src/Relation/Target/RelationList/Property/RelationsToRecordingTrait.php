<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationToRecording;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to a recording.
 */
trait RelationsToRecordingTrait
{
    /**
     * A list of relations to a recording
     *
     * @var RelationToRecording[]|RelationToRecordingList
     */
    private RelationToRecordingList $recordingRelations;

    /**
     * Returns a list of relations to a recording.
     *
     * @return RelationToRecording[]|RelationToRecordingList
     */
    public function getRecordingRelations(): RelationToRecordingList
    {
        return $this->recordingRelations;
    }

    /**
     * Sets a list of relations to a recording by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRecordingRelationsFromArray(array $input): void
    {
        $this->recordingRelations = is_null($input = ArrayAccess::getArray($input, EntityType::RECORDING))
            ? new RelationToRecordingList()
            : new RelationToRecordingList($input);
    }
}
