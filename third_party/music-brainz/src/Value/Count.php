<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A count
 */
class Count implements Value
{
    /**
     * The number
     *
     * @var null|int
     */
    private $number;

    /**
     * Constructs a count.
     *
     * @param int $number A number
     */
    public function __construct(int $number = null)
    {
        $this->number = ($number >= 0) ? $number : null;
    }

    /**
     * Returns the count as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->number;
    }

    /**
     * Returns the number.
     *
     * @return null|int
     */
    public function getNumber(): ?int
    {
        return $this->number;
    }
}
