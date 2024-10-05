<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument;

use MusicBrainz\Value\EntityType;

/**
 * A relation of an instrument to an url
 *
 * @link https://musicbrainz.org/relationships/instrument-url
 */
abstract class Url extends \MusicBrainz\Relation\Type\Instrument
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
