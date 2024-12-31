<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait WorkLevelRelationsTrait
{
    /**
     * True, if work level relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $workLevelRelations = false;

    /**
     * Returns true, if work level relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForWorkLevelRelations(): bool
    {
        return $this->workLevelRelations;
    }

    /**
     * Sets whether work level relations should be included.
     *
     * @param bool $workLevelRelations True, if work level relations should be included, otherwise false
     *
     * @return self
     */
    public function includeWorkLevelRelations(bool $workLevelRelations = true): self
    {
        $this->workLevelRelations = $workLevelRelations;

        return $this;
    }
}
