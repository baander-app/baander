<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a place to a recording
 *
 * @link https://musicbrainz.org/relationships/place-recording
 */
abstract class Recording extends \MusicBrainz\Relation\Type\Place
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
