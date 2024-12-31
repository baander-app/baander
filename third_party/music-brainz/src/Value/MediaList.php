<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of media
 */
class MediaList extends ValueList
{
    /**
     * Constructs a list of media.
     *
     * @param array $media An array of media information
     */
    public function __construct(array $media = [])
    {
        parent::__construct(
            array_map(
                function ($medium) {
                    return new Medium($medium);
                },
                $media
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Medium::class;
    }
}
