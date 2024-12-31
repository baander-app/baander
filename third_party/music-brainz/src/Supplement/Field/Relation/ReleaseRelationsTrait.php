<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait ReleaseRelationsTrait
{
    /**
     * True, if release relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $releaseRelations = false;

    /**
     * Returns true, if release relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForReleaseRelations(): bool
    {
        return $this->releaseRelations;
    }

    /**
     * Sets whether release relations should be included.
     *
     * @param bool $releaseRelations True, if release relations should be included, otherwise false
     *
     * @return self
     */
    public function includeReleaseRelations(bool $releaseRelations = true): self
    {
        $this->releaseRelations = $releaseRelations;

        return $this;
    }
}
