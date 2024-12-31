<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Genre\InfluencedBy;

use MusicBrainz\Relation\Type\Genre\Genre\InfluencedBy;
use MusicBrainz\Value\Name;

/**
 * This indicates that a genre originated as a hybrid of two or more other genres.
 *
 * @link https://musicbrainz.org/relationship/723732ec-762c-4cb3-a2d0-e7e797c51915
 */
class FusionOf extends InfluencedBy
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('fusion of');
    }
}
