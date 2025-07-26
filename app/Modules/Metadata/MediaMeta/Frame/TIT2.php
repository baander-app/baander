<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * TIT2 frame - Title/songname/content description.
 *
 * The 'Title/songname/content description' frame is the actual name of the piece
 * (e.g. "Adagio", "Hurricane Donna").
 */
class TIT2 extends TextFrame
{
    /**
     * Constructs the TIT2 frame with given parameters.
     *
     * @param string $title The title
     * @param int $encoding The text encoding
     */
    public function __construct(string $title = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TIT2', $title, $encoding);
    }

    /**
     * Returns the title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getText();
    }

    /**
     * Sets the title.
     *
     * @param string $title The title
     * @return self
     */
    public function setTitle(string $title): self
    {
        return $this->setText($title);
    }
}