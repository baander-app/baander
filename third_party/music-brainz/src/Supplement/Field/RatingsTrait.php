<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait RatingsTrait
{
    /**
     * True, if ratings should be included, otherwise false
     *
     * @var bool
     */
    protected bool $ratings = false;

    /**
     * Returns true, if ratings should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForRatings(): bool
    {
        return $this->ratings;
    }

    /**
     * Sets whether ratings should be included.
     *
     * @param bool $ratings True, if ratings should be included, otherwise false
     *
     * @return self
     */
    public function includeRatings(bool $ratings = true): self
    {
        $this->ratings = $ratings;

        return $this;
    }
}
