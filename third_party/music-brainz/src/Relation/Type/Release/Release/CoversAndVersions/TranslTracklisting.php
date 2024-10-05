<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;

use MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;
use MusicBrainz\Value\Name;

/**
 * This indicates that one release is identical to another release, but that the release title and track titles have been either translated (into another language) or transliterated (into another script).
 *
 * @link https://musicbrainz.org/relationship/fc399d47-23a7-4c28-bfcf-0607a562b644
 */
class TranslTracklisting extends CoversAndVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('transl-tracklisting');
    }
}
