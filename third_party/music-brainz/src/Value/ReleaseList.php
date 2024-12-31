<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of releases
 */
class ReleaseList extends ValueList
{
    /**
     * Constructs a list of releases.
     *
     * @param array $releases Information about the releases
     */
    public function __construct(array $releases = [])
    {
        parent::__construct(
            array_map(
                function ($release) {
                    return new Release($release);
                },
                $releases
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
        return Release::class;
    }
}
