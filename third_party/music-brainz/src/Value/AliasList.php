<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value\Property\List\CommaSeperatedListTrait;

/**
 * A list of aliases
 *
 * @see https://musicbrainz.org/doc/Aliases
 */
class AliasList extends ValueList
{
    use CommaSeperatedListTrait;

    /**
     * Constructs a list of aliases.
     *
     * @param array $aliases An array alias arrays
     */
    public function __construct(array $aliases = [])
    {
        parent::__construct(
            array_map(
                function ($alias) {
                    return new Alias($alias);
                },
                $aliases
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
        return Alias::class;
    }
}
