<?php

declare(strict_types=1);

namespace MusicBrainz\Supplement\Field;

trait IsrcsTrait
{
    /**
     * True, if ISRC's should be included, otherwise false
     *
     * @var bool
     */
    protected bool $isrcs = false;

    /**
     * Returns true, if ISRC's should be included, otherwise false.
     *
     * @return bool
     */
    public function getIncludeFlagForIsrcs(): bool
    {
        return $this->isrcs;
    }

    /**
     * Sets whether ISRC's should be included.
     *
     * @param bool $isrcs True, if ISRC's should be included, otherwise false
     *
     * @return self
     */
    public function includeIsrcs(bool $isrcs = true): self
    {
        $this->isrcs = $isrcs;

        return $this;
    }
}
