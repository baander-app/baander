<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TCOM frame - Composer.
 *
 * The 'Composer' frame is intended for the name of the composer.
 */
class TCOM extends TextFrame
{
    /**
     * Constructs the TCOM frame with given parameters.
     */
    public function __construct(string $composer = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TCOM', $composer, $encoding);
    }

    /**
     * Returns the composer.
     */
    public function getComposer(): string
    {
        return $this->getText();
    }

    /**
     * Sets the composer.
     */
    public function setComposer(string $composer): self
    {
        return $this->setText($composer);
    }
}