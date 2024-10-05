<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait UrlRelationsTrait
{
    /**
     * True, if URL relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $urlRelations = false;

    /**
     * Returns true, if URL relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForURLRelations(): bool
    {
        return $this->urlRelations;
    }

    /**
     * Sets whether URL relations should be included.
     *
     * @param bool $urlRelations True, if URL relations should be included, otherwise false
     *
     * @return self
     */
    public function includeUrlRelations(bool $urlRelations = true): self
    {
        $this->urlRelations = $urlRelations;

        return $this;
    }
}
