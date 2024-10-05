<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Relation\Type\Artist\ReleaseGroup;
use MusicBrainz\Value\ValueList;

/**
 * A list of release group relations
 */
class RelationToReleaseGroupList extends ValueList
{
    /**
     * Constructs a list of release group relations.
     *
     * @param array $releaseGroupRelations A list of release group relations
     */
    public function __construct(array $releaseGroupRelations = [])
    {
        parent::__construct($releaseGroupRelations);
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
