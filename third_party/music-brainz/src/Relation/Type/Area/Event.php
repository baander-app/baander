<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an area to an event
 *
 * @link https://musicbrainz.org/relationships/area-event
 */
abstract class Event extends \MusicBrainz\Relation\Type\Area
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::EVENT);
    }
}
