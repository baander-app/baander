<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of release types
 */
class ReleaseTypeList extends ValueList
{
    /**
     * Constructs a list of release types.
     *
     * @param array $releaseTypes Information about the releaseTypes
     */
    public function __construct(array $releaseTypes = [])
    {
        parent::__construct(
            array_map(
                function ($releaseType) {
                    return new ReleaseType($releaseType);
                },
                $releaseTypes
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
        return ReleaseType::class;
    }
}
