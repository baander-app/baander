<?php

declare(strict_types=1);

namespace MusicBrainz\Collection;

/**
 * Provides a collection with external iterator.
 *
 * @link http://php.net/manual/en/class.iterator.php
 */
trait IteratorTrait
{
    use CollectionTrait;

    /**
     * Rewinds the iterator to the first element.
     *
     * @link http://php.net/manual/en/iterator.rewind.php
     *
     * @return void
     */
    public function rewind(): void
    {
        reset($this->elements);
    }

    /**
     * Returns the current element.
     *
     * @link http://php.net/manual/en/iterator.current.php
     *
     * @return mixed
     */
    public function current(): mixed
    {
        return current($this->elements);
    }

    /**
     * Returns the key of the current element.
     *
     * @link http://php.net/manual/en/iterator.key.php
     *
     * @return string|int|null
     */
    public function key(): string|int|null
    {
        return key($this->elements);
    }

    /**
     * Moves forward to next element.
     *
     * @link http://php.net/manual/en/iterator.next.php
     *
     * @return void
     */
    public function next(): void
    {
        next($this->elements);
    }

    /**
     * Checks if current position is valid.
     *
     * @link http://php.net/manual/en/iterator.valid.php
     *
     * @return bool
     */
    public function valid(): bool
    {
        return key($this->elements) !== null;
    }
}
