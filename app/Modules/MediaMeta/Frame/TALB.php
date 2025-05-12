<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TALB frame - Album/Movie/Show title.
 *
 * The 'Album/Movie/Show title' frame is intended for the title of the recording
 * (or source of sound) from which the audio in the file is taken.
 */
class TALB extends TextFrame
{
    /**
     * Constructs the TALB frame with given parameters.
     *
     * @param string $album The album
     * @param int $encoding The text encoding
     */
    public function __construct(string $album = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TALB', $album, $encoding);
    }

    /**
     * Returns the album.
     *
     * @return string
     */
    public function getAlbum(): string
    {
        return $this->getText();
    }

    /**
     * Sets the album.
     *
     * @param string $album The album
     * @return self
     */
    public function setAlbum(string $album): self
    {
        return $this->setText($album);
    }
}