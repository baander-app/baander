<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Label;

use MusicBrainz\Relation\Type\Event\Label;
use MusicBrainz\Value\Name;

/**
 * Links an event to a label or other organization credited for presenting it (often as "Label presents Event").
 *
 * @link https://musicbrainz.org/relationship/09bd2cba-6160-408b-b191-afef7480ce54
 */
class Presented extends Label
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('presented');
    }
}
