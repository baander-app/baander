<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an event to a release group
 *
 * @link https://musicbrainz.org/relationships/event-release_group
 */
abstract class ReleaseGroup extends \MusicBrainz\Relation\Type\Event
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RELEASE_GROUP);
    }
}
