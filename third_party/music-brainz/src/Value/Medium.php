<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A medium
 */
class Medium implements Value
{
    use Property\DiscsTrait;
    use Property\FormatTrait;
    use Property\TitleTrait;
    use Property\TracksTrait;

    /**
     * Constructs a medium.
     *
     * @param array $medium Information about the medium
     */
    public function __construct(array $medium = [])
    {
        $this->setDiscsFromArray($medium);
        $this->setFormatFromArray($medium);
        $this->setTitleFromArray($medium);
        $this->setTracksFromArray($medium);
    }

    /**
     * Returns the title of the medium as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getTitle();
    }
}
