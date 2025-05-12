<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TPE3 frame - Conductor/performer refinement.
 *
 * The 'Conductor/performer refinement' frame is used for additional information about the conductor or performer.
 */
class TPE3 extends TextFrame
{
    /**
     * Constructs the TPE3 frame with given parameters.
     */
    public function __construct(string $conductor = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TPE3', $conductor, $encoding);
    }

    /**
     * Returns the conductor/performer refinement.
     */
    public function getConductor(): string
    {
        return $this->getText();
    }

    /**
     * Sets the conductor/performer refinement.
     */
    public function setConductor(string $conductor): self
    {
        return $this->setText($conductor);
    }
}