<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a recording to an url
 *
 * @link https://musicbrainz.org/relationships/recording-url
 */
abstract class Url extends \MusicBrainz\Relation\Type\Recording
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::URL);
    }
}
