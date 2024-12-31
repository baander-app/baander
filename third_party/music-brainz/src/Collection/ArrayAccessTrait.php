<?php

declare(strict_types=1);

namespace MusicBrainz\Collection;

use function array_key_exists;

/**
 * Provides accessing objects as arrays.
 *
 * @link http://php.net/manual/en/class.arrayaccess.php
 */
trait ArrayAccessTrait
{
    use CollectionTrait;

    /**
     * Returns a value of a given key.
     *
     * @param string $key A key
     *
     * @return mixed|null The value of the given key or null if key does not exist
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * Sets a given value to a given key.
     *
     * @param string $key A key
     * @param mixed $value A value
     *
     * @return void
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->elements[$key] = $value;
    }

    /**
     * Removes an element by a given key.
     *
     * @param string $key A key
     *
     * @return void
     */
    public function offsetUnset(mixed $key): void
    {
        unset($this->elements[$key]);
    }

    /**
     * Returns true, if the collection contains a given key, otherwise false.
     *
     * @param string $key A key
     *
     * @return bool True if key exists, otherwise false
     */
    public function offsetExists(mixed $key): bool
    {
        return array_key_exists($key, $this->elements);
    }
}