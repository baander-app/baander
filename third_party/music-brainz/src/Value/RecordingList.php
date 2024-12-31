<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of recordings
 */
class RecordingList extends ValueList
{
    /**
     * Constructs a list of recording.
     *
     * @param array $recording Information about the recording
     */
    public function __construct(array $recording = [])
    {
        parent::__construct(
            array_map(
                function ($recording) {
                    return new Recording($recording);
                },
                $recording
            )
        );
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
