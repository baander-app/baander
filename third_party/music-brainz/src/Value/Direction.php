<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * The direction of a relation
 */
class Direction implements Value
{
    /**
     * Backward
     *
     * @var string
     */
    public const BACKWARD = 'backward';

    /**
     * Forward
     *
     * @var string
     */
    public const FORWARD = 'forward';

    /**
     * A list of valid direction codes.
     *
     * @var string[]
     */
    public const DIRECTION_CODES = [
        self::BACKWARD,
        self::FORWARD,
    ];

    /**
     * The direction code
     *
     * @var string
     */
    private $directionCode;

    /**
     * Constructs a direction of a relation.
     *
     * @param string $directionCode A direction code
     */
    public function __construct(string $directionCode = '')
    {
        $this->directionCode = $directionCode;
    }

    /**
     * Returns the direction code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->directionCode;
    }
}
