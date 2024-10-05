<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * Indicates that the recording contains samples from material by the indicated artist. Use this only if you really cannot figure out the particular recording that has been sampled.
 *
 * @link https://musicbrainz.org/relationship/83f72956-2007-4bca-8a97-0ae539cca99d
 */
class SamplesFromArtist extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('samples from artist');
    }
}
