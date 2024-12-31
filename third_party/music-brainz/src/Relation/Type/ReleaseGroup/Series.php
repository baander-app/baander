<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a release group to a series
 *
 * @link https://musicbrainz.org/relationships/release_group-series
 */
abstract class Series extends \MusicBrainz\Relation\Type\ReleaseGroup
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
