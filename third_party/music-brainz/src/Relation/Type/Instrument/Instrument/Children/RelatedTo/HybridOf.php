<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Instrument\Children\RelatedTo;

use MusicBrainz\Relation\Type\Instrument\Instrument\Children\RelatedTo;
use MusicBrainz\Value\Name;

/**
 * This indicates that an instrument is a hybrid of two or more other instruments.
 *
 * @link https://musicbrainz.org/relationship/2f522cbc-46f9-409b-9957-d0308d0899ef
 */
class HybridOf extends RelatedTo
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('hybrid of');
    }
}
