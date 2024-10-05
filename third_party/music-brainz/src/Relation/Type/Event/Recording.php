<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an event to a recording
 *
 * @link https://musicbrainz.org/relationships/event-recording
 */
abstract class Recording extends \MusicBrainz\Relation\Type\Event
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RECORDING);
    }
}
