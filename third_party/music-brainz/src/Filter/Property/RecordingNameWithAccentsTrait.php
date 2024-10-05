<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\Name;

trait RecordingNameWithAccentsTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the name of the recording (with accented characters).
     *
     * @return string
     */
    public static function recordingNameWithAccent(): string
    {
        return 'recordingaccent';
    }

    /**
     * Adds the name of the recording (with accented characters).
     *
     * @param Name $recordingNameWithAccents The name of the recording (with accented characters)
     *
     * @return Phrase
     */
    public function addRecordingNameWithAccents(Name $recordingNameWithAccents): Phrase
    {
        return $this->addPhrase((string) $recordingNameWithAccents, self::recordingNameWithAccent());
    }
}
