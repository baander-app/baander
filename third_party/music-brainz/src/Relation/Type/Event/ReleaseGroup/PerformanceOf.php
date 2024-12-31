<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\ReleaseGroup;

use MusicBrainz\Relation\Type\Event\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * Indicates that an event was intended as a performance of a specific release group (usually an album).
 *
 * @link https://musicbrainz.org/relationship/a64a9085-505b-4588-bff9-214d7dda61c4
 */
class PerformanceOf extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('performance of');
    }
}
