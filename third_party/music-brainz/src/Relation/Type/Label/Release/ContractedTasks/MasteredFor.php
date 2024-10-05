<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Release\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Release was mastered by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/090fa87e-da92-4a19-b294-006fcedf3415
 */
class MasteredFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mastered for');
    }
}
