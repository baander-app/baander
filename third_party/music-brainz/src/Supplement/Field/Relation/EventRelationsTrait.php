<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait EventRelationsTrait
{
    /**
     * True, if event relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $eventRelations = false;

    /**
     * Returns true, if event relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForEventRelations(): bool
    {
        return $this->eventRelations;
    }

    /**
     * Sets whether event relations should be included.
     *
     * @param bool $eventRelations True, if event relations should be included, otherwise false
     *
     * @return self
     */
    public function includeEventRelations(bool $eventRelations = true): self
    {
        $this->eventRelations = $eventRelations;

        return $this;
    }
}
