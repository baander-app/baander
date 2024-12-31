<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * Indicates a page that reviews the release (group) in question, whether it be in text, audio or video form.
 *
 * @link https://musicbrainz.org/relationship/c3ac9c3b-f546-4d15-873f-b294d2c1b708
 */
class Review extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('review');
    }
}
