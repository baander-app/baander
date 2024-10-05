<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * This link points to a page for a particular release group within a discography for an artist or label. If the page is for a particular release, prefer the release level relationship.
 *
 * @link https://musicbrainz.org/relationship/5849de4a-78ae-4876-975d-2181107e70b7
 */
class DiscographyEntry extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('discography entry');
    }
}
