<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A disambiguation
 *
 * @link https://musicbrainz.org/doc/Disambiguation_Comment
 */
class Disambiguation implements Value
{
    /**
     * The disambiguation comment
     *
     * @var string
     */
    private $disambiguation;

    /**
     * Constructs a disambiguation.
     *
     * @param string $disambiguation The disambiguation comment
     */
    public function __construct(string $disambiguation = '')
    {
        $this->disambiguation = $disambiguation;
    }

    /**
     * Returns the string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->disambiguation;
    }
}
