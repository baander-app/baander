<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * An instrument name
 */
class InstrumentName implements Value
{
    /**
     * The instrument name
     *
     * @var string
     */
    private $name;

    /**
     * Constructs an instrument name.
     *
     * @param string $name The name
     */
    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    /**
     * Returns the instrument name as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
