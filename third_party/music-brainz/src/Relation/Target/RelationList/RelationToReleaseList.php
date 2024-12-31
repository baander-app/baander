<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Release;
use MusicBrainz\Value\ValueList;

/**
 * A list of release relations
 */
class RelationToReleaseList extends ValueList
{
    /**
     * Constructs a list of release relations.
     *
     * @param array $releaseRelations A list of release relations
     */
    public function __construct(array $releaseRelations = [])
    {
        parent::__construct($releaseRelations);
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
