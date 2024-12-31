<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Ownership;

use MusicBrainz\Relation\Type\Artist\Label\Ownership;
use MusicBrainz\Value\Name;

/**
 * This indicates a personal publishing label for an artist. A personal publishing label is a small label (usually a subdivision of a larger one) that exclusively handles the rights to works by that artist.
 *
 * @link https://musicbrainz.org/relationship/8fecc8a7-0df7-4637-9152-f12a07f0e9cd
 */
class PersonalPublisher extends Ownership
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('personal publisher');
    }
}
