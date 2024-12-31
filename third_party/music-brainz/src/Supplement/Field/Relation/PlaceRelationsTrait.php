<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait PlaceRelationsTrait
{
    /**
     * True, if place relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $placeRelations = false;

    /**
     * Returns true, if place relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForPlaceRelations(): bool
    {
        return $this->placeRelations;
    }

    /**
     * Sets whether place relations should be included.
     *
     * @param bool $placeRelations True, if place relations should be included, otherwise false
     *
     * @return self
     */
    public function includePlaceRelations(bool $placeRelations = true): self
    {
        $this->placeRelations = $placeRelations;

        return $this;
    }
}
