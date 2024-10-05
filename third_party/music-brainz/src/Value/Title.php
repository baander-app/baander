<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A title
 */
class Title implements Value
{
    /**
     * The title
     *
     * @var string
     */
    private $title;

    /**
     * Constructs a title.
     *
     * @param string $title The title
     */
    public function __construct(string $title = '')
    {
        $this->title = $title;
    }

    /**
     * Returns the string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->title;
    }
}
