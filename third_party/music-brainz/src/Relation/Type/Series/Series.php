<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a series to a series
 *
 * @link https://musicbrainz.org/relationships/series-series
 */
abstract class Series extends \MusicBrainz\Relation\Type\Series
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
