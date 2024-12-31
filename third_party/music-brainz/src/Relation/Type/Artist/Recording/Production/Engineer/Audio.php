<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer involved with the machines used to generate sound, such as effects processors and digital audio equipment used to modify or manipulate sound in either an analogue or digital form.
 *
 * @link https://musicbrainz.org/relationship/ca8d6d99-b847-439c-b0ec-33d8a1b942bc
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
