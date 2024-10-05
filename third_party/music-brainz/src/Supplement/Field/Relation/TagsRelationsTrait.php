<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field\Relation;

trait TagsRelationsTrait
{
    /**
     * True, if tags relations should be included, otherwise false
     *
     * @var bool
     */
    protected bool $tagsRelations = false;

    /**
     * Returns true, if tags relations should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForTagsRelations(): bool
    {
        return $this->tagsRelations;
    }

    /**
     * Sets whether tags relations should be included.
     *
     * @param bool $tagsRelations True, if tags relations should be included, otherwise false
     *
     * @return self
     */
    public function includeTagsRelations(bool $tagsRelations = true): self
    {
        $this->tagsRelations = $tagsRelations;

        return $this;
    }
}
