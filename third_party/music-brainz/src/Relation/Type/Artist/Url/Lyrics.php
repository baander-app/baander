<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url;

use MusicBrainz\Relation\Type\Artist\Url;
use MusicBrainz\Value\Name;

/**
 * This relationship describes a URL where lyrics for the artist can be found. Only sites on the whitelist are permitted.
 *
 * @link https://musicbrainz.org/relationship/e4d73442-3762-45a8-905c-401da65544ed
 */
class Lyrics extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lyrics');
    }
}
