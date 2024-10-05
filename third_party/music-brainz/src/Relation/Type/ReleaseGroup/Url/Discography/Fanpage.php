<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * This links a release group to a fan-created website.
 *
 * @link https://musicbrainz.org/relationship/fd9e6ea5-851f-40ab-b15f-4548af61a25f
 */
class Fanpage extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('fanpage');
    }
}
