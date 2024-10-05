<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Recording\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Recording\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Recording was remixed by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/317009f8-9f7f-46e4-9d3e-5d69a56ed08f
 */
class RemixedFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remixed for');
    }
}
