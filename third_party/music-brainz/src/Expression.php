<?php

declare(strict_types=1);

namespace MusicBrainz;

/**
 * An expression
 */
interface Expression
{
    /**
     * Returns a string representation of the object.
     *
     * @return string
     */
    public function __toString(): string;
}
