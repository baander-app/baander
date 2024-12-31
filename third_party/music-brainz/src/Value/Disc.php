<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A disc
 */
class Disc implements Value
{
    use Property\DiscIdTrait;
    use Property\SectorOffsetsTrait;
    use Property\SectorsTrait;

    /**
     * Constructs a disc.
     *
     * @param array $disc Information about the disc
     */
    public function __construct(array $disc = [])
    {
        $this->setDiscIdFromArray($disc);
        $this->setSectorOffsetsFromArray($disc);
        $this->setSectorsFromArray($disc);
    }

    /**
     * Returns the disc ID as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getDiscId();
    }
}
