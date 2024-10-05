<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a place to a work
 *
 * @link https://musicbrainz.org/relationships/place-work
 */
abstract class Work extends \MusicBrainz\Relation\Type\Place
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::WORK);
    }
}
