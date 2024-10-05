<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A sort index for a name
 */
class SortName implements Value
{
    /**
     * The sort index
     *
     * @var string
     */
    private $index;

    /**
     * Constructs a sort index for a name.
     *
     * @param string $index The sort index
     */
    public function __construct(string $index = '')
    {
        $this->index = $index;
    }

    /**
     * Returns the string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->index;
    }
}
