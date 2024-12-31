<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a label to a release group
 *
 * @link https://musicbrainz.org/relationships/label-release_group
 */
abstract class ReleaseGroup extends \MusicBrainz\Relation\Type\Label
{
    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    final public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::RELEASE_GROUP);
    }
}
