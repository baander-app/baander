<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait UserTagsTrait
{
    /**
     * True, if user tags should be included, otherwise false
     *
     * @var bool
     */
    protected bool $userTags = false;

    /**
     * Returns true, if user tags should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForUserTags(): bool
    {
        return $this->userTags;
    }

    /**
     * Sets whether user tags should be included.
     *
     * @param bool $userTags True, if user tags should be included, otherwise false
     *
     * @return self
     */
    public function includeUserTags(bool $userTags = true): self
    {
        $this->userTags = $userTags;

        return $this;
    }
}
