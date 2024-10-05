<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * Indicates that the recording contains samples from another.
 *
 * @link https://musicbrainz.org/relationship/9efd9ce9-e702-448b-8e76-641515e8fe62
 */
class SamplesMaterial extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('samples material');
    }
}
