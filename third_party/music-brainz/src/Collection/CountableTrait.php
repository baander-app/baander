<?php

declare(strict_types=1);

namespace MusicBrainz\Collection;

use function count;

/**
 * Provides an implementation of the PHP countable interface.
 *
 * @link http://php.net/manual/en/class.countable.php
 */
trait CountableTrait
{
    use CollectionTrait;

    /**
     * Returns the number of elements.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->elements);
    }
}
