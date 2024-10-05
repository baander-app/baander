<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait ReleasesTrait
{
    /**
     * True, if releases should be included, otherwise false
     *
     * @var bool
     */
    protected bool $releases = false;

    /**
     * Returns true, if releases should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForReleases(): bool
    {
        return $this->releases;
    }

    /**
     * Sets whether releases should be included.
     *
     * @param bool $releases True, if releases should be included, otherwise false
     *
     * @return self
     */
    public function includeReleases(bool $releases = true): self
    {
        $this->releases = $releases;

        return $this;
    }
}
