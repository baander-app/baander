<?php

declare(strict_types=1);

namespace MusicBrainz\Relation;

use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Name;

/**
 * An undefined relation type
 */
class NullType extends Type
{
    /**
     * Returns the entity type of the base entity.
     *
     * @return EntityType
     */
    public static function getBaseEntityType(): EntityType
    {
        return new EntityType();
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType();
    }

    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('is ralated to');
    }
}
