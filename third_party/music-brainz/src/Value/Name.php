<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A name
 *
 * - The official name of the artist, be it a person or a band
 * - The name of a label
 * - The name of an alias
 * - The name of an area
 * - The name of a tag
 */
class Name implements Value
{
    /**
     * The name
     *
     * @var string
     */
    private $name;

    /**
     * Constructs a name.
     *
     * @param string $name The name
     */
    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    /**
     * Returns the string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
