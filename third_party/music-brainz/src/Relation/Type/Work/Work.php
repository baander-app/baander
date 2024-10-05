<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work;

use MusicBrainz\Value\EntityType;

/**
 * A relation of a work to a work
 *
 * @link https://musicbrainz.org/relationships/work-work
 */
abstract class Work extends \MusicBrainz\Relation\Type\Work
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
