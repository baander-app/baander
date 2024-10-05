<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait UserGenresTrait
{
    /**
     * True, if user genres should be included, otherwise false
     *
     * @var bool
     */
    protected bool $userGenres = false;

    /**
     * Returns true, if user genres should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForUserGenres(): bool
    {
        return $this->userGenres;
    }

    /**
     * Sets whether user genres should be included.
     *
     * @param bool $userGenres True, if user genres should be included, otherwise false
     *
     * @return self
     */
    public function includeUserGenres(bool $userGenres = true): self
    {
        $this->userGenres = $userGenres;

        return $this;
    }
}
