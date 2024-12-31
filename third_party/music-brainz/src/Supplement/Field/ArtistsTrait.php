<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait ArtistsTrait
{
    /**
     * True, if artists should be included, otherwise false
     *
     * @var bool
     */
    protected bool $artists = false;

    /**
     * Returns true, if artists should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForArtists(): bool
    {
        return $this->artists;
    }

    /**
     * Sets whether artists should be included.
     *
     * @param bool $artists True, if artists should be included, otherwise false
     *
     * @return self
     */
    public function includeArtists(bool $artists = true): self
    {
        $this->artists = $artists;

        return $this;
    }
}
