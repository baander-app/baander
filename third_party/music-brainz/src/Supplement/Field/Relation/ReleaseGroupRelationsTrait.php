<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait ReleaseGroupRelationsTrait
{
    /**
     * True, if release group relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $releaseGroupRelations = false;

    /**
     * Returns true, if release group relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForReleaseGroupRelations(): bool
    {
        return $this->releaseGroupRelations;
    }

    /**
     * Sets whether release group relations should be included.
     *
     * @param bool $releaseGroupRelations True, if release group relations should be included, otherwise false
     *
     * @return self
     */
    public function includeReleaseGroupRelations(bool $releaseGroupRelations = true): self
    {
        $this->releaseGroupRelations = $releaseGroupRelations;

        return $this;
    }
}
