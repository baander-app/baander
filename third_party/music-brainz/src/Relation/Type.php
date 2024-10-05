<?php

declare(strict_types=1);

namespace MusicBrainz\Relation;

use MusicBrainz\Definition\RelationTypeId;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;

/**
 * A relation type
 */
abstract class Type
{
    /**
     * Returns the entity type of the base entity.
     *
     * @return EntityType
     */
    abstract public static function getBaseEntityType(): EntityType;

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    abstract public static function getRelatedEntityType(): EntityType;

    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    abstract public static function getRelationName(): Name;

    /**
     * Returns the UUID of the relation.
     *
     * @return MBID
     */
    final public static function getRelationTypeId(): MBID
    {
        return new MBID(
            // @phpstan-ignore-next-line Each relation type is defined in class map
            array_search(
                self::class,
                RelationTypeId::getClassMap()
            )
        );
    }
}
