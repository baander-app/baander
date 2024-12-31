<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait ReleaseGroupsTrait
{
    /**
     * True, if release groups should be included, otherwise false
     *
     * @var bool
     */
    protected bool $releaseGroups = false;

    /**
     * Returns true, if release groups should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForReleaseGroups(): bool
    {
        return $this->releaseGroups;
    }

    /**
     * Sets whether release groups should be included.
     *
     * @param bool $releaseGroups True, if release groups should be included, otherwise false
     *
     * @return self
     */
    public function includeReleaseGroups(bool $releaseGroups = true): self
    {
        $this->releaseGroups = $releaseGroups;

        return $this;
    }
}
