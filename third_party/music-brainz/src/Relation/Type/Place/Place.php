<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a place to a place
 *
 * @link https://musicbrainz.org/relationships/place-place
 */
abstract class Place extends \MusicBrainz\Relation\Type\Place
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::PLACE);
    }
}
