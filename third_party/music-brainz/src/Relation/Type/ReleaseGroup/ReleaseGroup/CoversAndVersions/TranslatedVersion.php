<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\CoversAndVersions;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\CoversAndVersions;
use MusicBrainz\Value\Name;

/**
 * This is used to indicate that a release group is a translated version of another.
 *
 * @link https://musicbrainz.org/relationship/7c303515-05a8-46fc-baae-d15d76cef286
 */
class TranslatedVersion extends CoversAndVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('translated version');
    }
}
