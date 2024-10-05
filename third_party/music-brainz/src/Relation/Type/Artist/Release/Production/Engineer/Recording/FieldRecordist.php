<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer\Recording;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates a recording engineer that recorded field recordings for the release.
 *
 * @link https://musicbrainz.org/relationship/d92d4280-e288-4268-81ca-4c7252dfe7c3
 */
class FieldRecordist extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('field recordist');
    }
}
