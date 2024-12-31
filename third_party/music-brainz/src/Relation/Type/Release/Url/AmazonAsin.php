<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url;

use MusicBrainz\Relation\Type\Release\Url;
use MusicBrainz\Value\Name;

/**
 * This links a MusicBrainz release to the equivalent entry at Amazon.
 *
 * @link https://musicbrainz.org/relationship/4f2e710d-166c-480c-a293-2e2c8d658d87
 */
class AmazonAsin extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('amazon asin');
    }
}
