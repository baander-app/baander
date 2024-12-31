<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a release to a release
 *
 * @link https://musicbrainz.org/relationships/release-release
 */
abstract class Release extends \MusicBrainz\Relation\Type\Release
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RELEASE);
    }
}
