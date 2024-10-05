<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link a release group to an official website created specifically for the release group.
 *
 * @link https://musicbrainz.org/relationship/87d97dfc-3206-42fd-89d5-99593d5f1297
 */
class OfficialHomepage extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('official homepage');
    }
}
