<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing\Manufactured;

use MusicBrainz\Relation\Type\Label\Release\Publishing\Manufactured;
use MusicBrainz\Value\Name;

/**
 * This indicates the pressing company that presses a release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/c55ab145-0618-4465-8034-b5c6742bbe9e
 */
class Pressed extends Manufactured
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('pressed');
    }
}
