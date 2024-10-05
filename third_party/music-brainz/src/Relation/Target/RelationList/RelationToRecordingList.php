<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Recording;
use MusicBrainz\Value\ValueList;

/**
 * A list of recording relations
 */
class RelationToRecordingList extends ValueList
{
    /**
     * Constructs a list of recording relations.
     *
     * @param array $recordingRelations A list of recording relations
     */
    public function __construct(array $recordingRelations = [])
    {
        parent::__construct($recordingRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Recording::class;
    }
}
