<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a label to a series
 *
 * @link https://musicbrainz.org/relationships/label-series
 */
abstract class Series extends \MusicBrainz\Relation\Type\Label
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
