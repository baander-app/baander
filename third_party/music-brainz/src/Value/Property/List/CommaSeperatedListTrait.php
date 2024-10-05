<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property\List;

use MusicBrainz\Collection\CollectionTrait;
use MusicBrainz\Value;

/**
 * Provides a __toString()-Method for value lists.
 */
trait CommaSeperatedListTrait
{
    use CollectionTrait;

    /**
     * Returns a comma separated list of values.
     *
     * @return string
     */
    public function __toString(): string
    {
        return implode(
            ', ',
            array_map(
                function (Value $value): string {
                    return (string) $value;
                },
                $this->elements
            )
        );
    }
}
