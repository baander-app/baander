<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This links a DJ-mix to the artist who mixed it.
 *
 * @link https://musicbrainz.org/relationship/28338ee6-d578-485a-bb53-61dbfd7c6545
 */
class MixDJ extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mix-DJ');
    }
}
