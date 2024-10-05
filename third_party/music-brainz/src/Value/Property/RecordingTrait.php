<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Recording;

use function is_null;

/**
 * Provides a getter for a recording.
 */
trait RecordingTrait
{
    /**
     * The recording
     *
     * @var Recording
     */
    public Recording $recording;

    /**
     * Returns the recording.
     *
     * @return Recording
     */
    public function getRecording(): Recording
    {
        return $this->recording;
    }

    /**
     * Sets the recording by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRecordingFromArray(array $input, ?string $key = 'recording'): void
    {
        if (is_null($key)) {
            $this->recording = new Recording($input);

            return;
        }

        $this->recording = is_null($recording = ArrayAccess::getArray($input, $key))
            ? new Recording()
            : new Recording($recording);
    }
}
