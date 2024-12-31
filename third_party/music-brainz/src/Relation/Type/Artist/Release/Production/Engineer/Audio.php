<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer involved with the machines used to generate sound, such as effects processors and digital audio equipment used to modify or manipulate sound in either an analogue or digital form.
 *
 * @link https://musicbrainz.org/relationship/b04848d7-dbd9-4be0-9d8c-13df6d6e40db
 */
class Audio extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('audio');
    }
}
