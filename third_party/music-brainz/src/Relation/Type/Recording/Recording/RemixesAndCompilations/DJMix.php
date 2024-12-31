<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This is used to link a DJ-mixed recording to each of the source recordings. See DJ-mixer for crediting the person who created the DJ-mix.
 *
 * @link https://musicbrainz.org/relationship/451076df-61cf-46ab-9921-555cab2f050d
 */
class DJMix extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('DJ-mix');
    }
}
