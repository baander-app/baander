<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an artist to an artist
 *
 * @link https://musicbrainz.org/relationships/artist-artist
 */
abstract class Artist extends \MusicBrainz\Relation\Type\Artist
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::ARTIST);
    }
}
