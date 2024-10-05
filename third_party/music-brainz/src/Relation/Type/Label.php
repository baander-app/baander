<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type;

use MusicBrainz\Relation\Type;
use MusicBrainz\Value\EntityType;

/**
 * A relation of a label
 */
abstract class Label extends Type
{
    /**
     * Returns the entity type of the base entity.
     *
     * @return EntityType
     */
    final public static function getBaseEntityType(): EntityType
    {
        return new EntityType(EntityType::LABEL);
    }
}
