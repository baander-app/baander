<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TRCK frame - Track number/Position in set.
 *
 * The 'Track number/Position in set' frame is a numeric string containing the order number of the
 * audio-file on its original recording. This may be extended with a "/" character and a numeric string
 * containing the total number of tracks/elements on the original recording. E.g. "4/9".
 */
class TRCK extends TextFrame
{
    /**
     * Constructs the TRCK frame with given parameters.
     */
    public function __construct(string $track = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TRCK', $track, $encoding);
    }

    /**
     * Returns the track number.
     */
    public function getTrack(): string
    {
        return $this->getText();
    }

    /**
     * Sets the track number.
     */
    public function setTrack(string $track): self
    {
        return $this->setText($track);
    }
}