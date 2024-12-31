<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait AliasesTrait
{
    /**
     * True, if aliases should be included, otherwise false
     *
     * @var bool
     */
    protected bool $aliases = false;

    /**
     * Returns true, if aliases should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForAliases(): bool
    {
        return $this->aliases;
    }

    /**
     * Sets whether aliases should be included.
     *
     * @param bool $aliases True, if aliases should be included, otherwise false
     *
     * @return self
     */
    public function includeAliases(bool $aliases = true): self
    {
        $this->aliases = $aliases;

        return $this;
    }
}
