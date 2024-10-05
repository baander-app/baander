<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Misc;

use MusicBrainz\Relation\Type\Artist\Work\Misc;
use MusicBrainz\Value\Name;

/**
 * Indicates the artist(s) who gave the first performance of the work; this is usually mostly relevant for classical music.
 *
 * @link https://musicbrainz.org/relationship/5cc8cfb5-cca0-4395-a44b-b7d3c1777608
 */
class Premiere extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('premiere');
    }
}
