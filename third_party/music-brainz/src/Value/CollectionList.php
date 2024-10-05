<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of collections
 */
class CollectionList extends ValueList
{
    /**
     * Constructs a list of collections.
     *
     * @param array $collections Information about the collections
     */
    public function __construct(array $collections = [])
    {
        parent::__construct(
            array_map(
                function ($collection) {
                    return new Collection($collection);
                },
                $collections
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Collection::class;
    }
}
