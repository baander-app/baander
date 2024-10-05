<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * This links a series to its profile page (such as for a festival) or project page (such as for a specific tour, or for compiling a classical catalogue) at a crowdfunding site like Kickstarter or Indiegogo.
 *
 * @link https://musicbrainz.org/relationship/b4894e57-5e32-479f-b1e7-bc561048ce48
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
