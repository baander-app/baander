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
     * @return null|mixed
     */
    public function offsetGet($key): mixed
    {
        return $this->elements[$key] ?? null;
    }

    /**
     * Sets a given value to a given key.
     *
     * @param string $key   A key
     * @param mixed  $value A value
     *
     * @return void
     */
    public function offsetSet($key, mixed $value): void
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
    public function offsetUnset($key): void
    {
        unset($this->elements[$key]);
    }

    /**
     * Returns true, if the collection contains a given key, otherwise false.
     *
     * @param string $key A key
     *
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return array_key_exists($key, $this->elements);
    }
}
