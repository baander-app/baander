<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An "ended" flag
 */
class Ended implements Value
{
    /**
     * True, if the object is already ended, otherwise false
     *
     * @var bool
     */
    private $ended;

    /**
     * Constructs an "ended" flag.
     *
     * @param bool $ended True, if the object is already ended, otherwise false
     */
    public function __construct(bool $ended = false)
    {
        $this->ended = $ended;
    }

    /**
     * Returns true, if the object is already ended, otherwise false.
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Returns the string "true", if the object is already ended, otherwise "false".
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->ended;
    }
}
