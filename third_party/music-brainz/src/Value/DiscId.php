<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A disc ID
 * A disc ID is the code number which MusicBrainz uses to link a physical CD to a release listing.
 *
 * @link https://musicbrainz.org/doc/Disc_ID
 */
class DiscId implements Value
{
    /**
     * The ID
     *
     * @var string
     */
    private $id;

    /**
     * Constructs a disc ID.
     *
     * @param string $id A disc ID
     */
    public function __construct(string $id = '')
    {
        $this->id = $id;
    }

    /**
     * Returns the disc ID as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->id;
    }
}
