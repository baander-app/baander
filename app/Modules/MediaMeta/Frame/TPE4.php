<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TPE4 frame - Interpreted, remixed, or otherwise modified by.
 *
 * The 'Interpreted, remixed, or otherwise modified by' frame contains additional information about the person
 * who interpreted, remixed, or otherwise modified the recording.
 */
class TPE4 extends TextFrame
{
    /**
     * Constructs the TPE4 frame with given parameters.
     */
    public function __construct(string $modifier = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TPE4', $modifier, $encoding);
    }

    /**
     * Returns the interpreter/remixer/modifier.
     */
    public function getModifier(): string
    {
        return $this->getText();
    }

    /**
     * Sets the interpreter/remixer/modifier.
     */
    public function setModifier(string $modifier): self
    {
        return $this->setText($modifier);
    }
}