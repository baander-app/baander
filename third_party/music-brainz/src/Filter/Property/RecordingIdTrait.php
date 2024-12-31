<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait RecordingIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the MusicBrainz ID (MBID) of a recording.
     *
     * @return string
     */
    public static function recordingId(): string
    {
        return 'rid';
    }

    /**
     * Adds the MusicBrainz ID (MBID) of a recording.
     *
     * @param MBID $recordingId The MusicBrainz ID (MBID) of a recording
     *
     * @return Term
     */
    public function addRecordingId(MBID $recordingId): Term
    {
        return $this->addTerm((string) $recordingId, self::recordingId());
    }
}
