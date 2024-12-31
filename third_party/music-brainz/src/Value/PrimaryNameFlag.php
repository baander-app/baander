<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A "primary name" flag
 */
class PrimaryNameFlag implements Value
{
    /**
     * True, if the name is a primary name, otherwise false
     *
     * @var bool
     */
    private $primaryName;

    /**
     * Constructs a "primary name" flag.
     *
     * @param bool $primaryName True, if the name is a primary name, otherwise false
     */
    public function __construct(bool $primaryName = false)
    {
        $this->primaryName = $primaryName;
    }

    /**
     * Returns the string "true", if the name is a primary name, otherwise "false".
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->primaryName;
    }

    /**
     * Returns true, if the name is a primary name, otherwise false.
     *
     * @return bool
     */
    public function isPrimaryName(): bool
    {
        return $this->primaryName;
    }
}
