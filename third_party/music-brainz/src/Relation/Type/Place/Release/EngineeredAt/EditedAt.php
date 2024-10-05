<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was edited at.
 *
 * @link https://musicbrainz.org/relationship/9b678c30-453a-47c4-8f69-9ba96b2c5e9c
 */
class EditedAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('edited at');
    }
}
