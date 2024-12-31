<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait ArtistCreditsTrait
{
    /**
     * True, if artist credits should be included, otherwise false
     *
     * @var bool
     */
    protected bool $artistCredits = false;

    /**
     * Returns true, if artist credits should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForArtistCredits(): bool
    {
        return $this->artistCredits;
    }

    /**
     * Sets whether artist credits should be included.
     *
     * @param bool $artistCredits True, if artist credits should be included, otherwise false
     *
     * @return self
     */
    public function includeArtistCredits(bool $artistCredits = true): self
    {
        $this->artistCredits = $artistCredits;

        return $this;
    }
}
