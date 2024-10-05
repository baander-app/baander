<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a recording to a series
 *
 * @link https://musicbrainz.org/relationships/recording-series
 */
abstract class Series extends \MusicBrainz\Relation\Type\Recording
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::SERIES);
    }
}
