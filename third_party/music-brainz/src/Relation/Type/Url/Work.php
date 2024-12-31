<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an url to a work
 *
 * @link https://musicbrainz.org/relationships/url-work
 */
abstract class Work extends \MusicBrainz\Relation\Type\Url
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
