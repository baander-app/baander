<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\Discography;

use MusicBrainz\Relation\Type\Artist\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * This links an artist to an online discography of their works. The discography should provide a summary of most, if not all, releases by the artist, and be as comprehensive as possible.
 *
 * @link https://musicbrainz.org/relationship/4fb0eeec-a6eb-4ae3-ad52-b55765b94e8f
 */
class DiscographyPage extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('discography page');
    }
}
