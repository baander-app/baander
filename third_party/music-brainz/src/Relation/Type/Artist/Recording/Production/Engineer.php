<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production;

use MusicBrainz\Relation\Type\Artist\Recording\Production;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer who performed a general engineering role.
 *
 * @link https://musicbrainz.org/relationship/5dcc52af-7064-4051-8d62-7d80f4c3c907
 */
class Engineer extends Production
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('engineer');
    }
}
