<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url;

use MusicBrainz\Relation\Type\Release\Url;
use MusicBrainz\Value\Name;

/**
 * This links a release to the relevant crowdfunding project at a crowdfunding site like Kickstarter or Indiegogo.
 *
 * @link https://musicbrainz.org/relationship/e1434bc9-5e54-4b10-b3f6-db09e6f0cb44
 */
class Crowdfunding extends Url
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
