<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links an artist to their profile at a crowdfunding site like Kickstarter or Indiegogo.
 *
 * @link https://musicbrainz.org/relationship/93883cf6-e818-4938-990e-75863f8db2d3
 */
class Crowdfunding extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('crowdfunding');
    }
}
