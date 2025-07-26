<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * TPE1 frame - Lead performer(s)/Soloist(s).
 *
 * The 'Lead performer(s)/Soloist(s)' frame is used for the main artist(s).
 * They are separated with the "/" character.
 */
class TPE1 extends TextFrame
{
    /**
     * Constructs the TPE1 frame with given parameters.
     *
     * @param string $artist The artist
     * @param int $encoding The text encoding
     */
    public function __construct(string $artist = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TPE1', $artist, $encoding);
    }

    /**
     * Returns the artist.
     *
     * @return string
     */
    public function getArtist(): string
    {
        return $this->getText();
    }

    /**
     * Sets the artist.
     *
     * @param string $artist The artist
     * @return self
     */
    public function setArtist(string $artist): self
    {
        return $this->setText($artist);
    }

    /**
     * Returns the artists as an array.
     *
     * @return array<string>
     */
    public function getArtists(): array
    {
        return array_map('trim', explode('/', $this->getText()));
    }

    /**
     * Sets the artists from an array.
     *
     * @param array<string> $artists The artists
     * @return self
     */
    public function setArtists(array $artists): self
    {
        return $this->setText(implode('/', $artists));
    }
}