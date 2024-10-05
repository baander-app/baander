<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\URL;
use MusicBrainz\Value\ValueList;

/**
 * A list of URL relations
 */
class RelationToUrlList extends ValueList
{
    /**
     * Constructs a list of URL relations.
     *
     * @param array $urlRelations A list of URL relations
     */
    public function __construct(array $urlRelations = [])
    {
        parent::__construct($urlRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return URL::class;
    }
}
