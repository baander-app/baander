<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\TrackDisplayNumber;

trait TrackDisplayNumberTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the displayed track number.
     *
     * @return string
     */
    public static function trackDisplayNumber(): string
    {
        return 'number';
    }

    /**
     * Adds a displayed track number.
     *
     * @param TrackDisplayNumber $trackDisplayNumber A displayed track number
     *
     * @return Phrase
     */
    public function addTrackDisplayNumber(TrackDisplayNumber $trackDisplayNumber): Phrase
    {
        return $this->addPhrase((string) $trackDisplayNumber, self::trackId());
    }
}
