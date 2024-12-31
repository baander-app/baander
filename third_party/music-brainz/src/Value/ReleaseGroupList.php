<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of release groups
 */
class ReleaseGroupList extends ValueList
{
    /**
     * Constructs a list of release groups.
     *
     * @param array $releaseGroups An array of media information
     */
    public function __construct(array $releaseGroups = [])
    {
        parent::__construct(
            array_map(
                function ($releaseGroup) {
                    return new ReleaseGroup($releaseGroup);
                },
                $releaseGroups
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
        return ReleaseGroup::class;
    }
}
