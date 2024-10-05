<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist (usually a visual artist) to their art gallery page(s), such as DeviantArt or pixiv.
 *
 * @link https://musicbrainz.org/relationship/8203341a-27be-40bb-b755-08d8ca9d7a9c
 */
class ArtGallery extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('art gallery');
    }
}
