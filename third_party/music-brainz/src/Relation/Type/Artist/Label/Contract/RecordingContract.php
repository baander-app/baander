<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Contract;

use MusicBrainz\Relation\Type\Artist\Label\Contract;
use MusicBrainz\Value\Name;

/**
 * This indicates that an artist had a recording contract with a label.
 *
 * @link https://musicbrainz.org/relationship/b336d682-592f-4486-9f45-3d5d59895bdc
 */
class RecordingContract extends Contract
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recording contract');
    }
}
