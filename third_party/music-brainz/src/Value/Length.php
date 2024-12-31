<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A length
 */
class Length implements Value
{
    /**
     * The length in milli seconds
     *
     * @var int
     */
    private $length;

    /**
     * Constructs a length.
     *
     * @param int $length The length
     */
    public function __construct(int $length = 0)
    {
        $this->length = $length;
    }

    /**
     * Returns the string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->length;
        //date('i:s', floor($this->length/1000));
    }
}
