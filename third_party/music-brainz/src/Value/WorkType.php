<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A type of work
 *
 * @see https://musicbrainz.org/doc/Workes
 */
class WorkType implements Value
{
    /**
     * The code for the type of work
     *
     * @var string
     */
    private $typeCode;

    /**
     * Constructs a type of work.
     *
     * @param string $typeCode A type of work
     */
    public function __construct(string $typeCode = '')
    {
        $this->typeCode = $typeCode;
    }

    /**
     * Returns the code for the type of work.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
