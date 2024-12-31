<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Artist;
use MusicBrainz\Value\ValueList;

/**
 * A list of artist relations
 */
class RelationToArtistList extends ValueList
{
    /**
     * Constructs a list of artist relations.
     *
     * @param array $artistRelations A list of artist relations
     */
    public function __construct(array $artistRelations = [])
    {
        parent::__construct($artistRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Artist::class;
    }
}
