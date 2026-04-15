<?php

namespace App\Primitives;

use App\Primitives\Traits\ImmutableBuilder;
use Illuminate\Contracts\Support\Arrayable;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Traversable;

class Sequence implements Arrayable, \JsonSerializable, \Countable, \IteratorAggregate
{
    use ImmutableBuilder;

    /**
     * @param array<int|string, mixed> $items
     */
    protected function __construct(
        protected array $items = [],
    ) {}

    /**
     * Create a new Sequence instance.
     *
     * @param iterable<int|string, mixed> $array
     */
    public static function make(iterable|Arrayable $array = []): static
    {
        if ($array instanceof Arrayable) {
            $array = $array->toArray();
        } elseif ($array instanceof Traversable) {
            $array = iterator_to_array($array);
        }

        return new static($array);
    }

    // -------------------------------------------------------------------------
    // Static utility methods (replacing Illuminate\Support\Arr usage)
    // -------------------------------------------------------------------------

    /**
     * Get the first item from the array, optionally matching a callback.
     */
    private static function doFirst(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return value($default);
            }
            foreach ($array as $item) {
                return $item;
            }

            return value($default);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    /**
     * If the given value is not an array and not an Arrayable, wrap it in one.
     */
    public static function wrap(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * Get the last item from the array, optionally matching a callback.
     */
    private static function doLast(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return value($default);
            }

            $result = null;
            $hasResult = false;

            foreach ($array as $item) {
                $result = $item;
                $hasResult = true;
            }

            return $hasResult ? $result : value($default);
        }

        $result = value($default);

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $result = $value;
            }
        }

        return $result;
    }

    /**
     * Check if one or more keys exist in the array.
     */
    public static function has(iterable $array, string|int|array $keys): bool
    {
        $keys = (array) $keys;

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! self::exists($array, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get an item from an array using "dot" notation.
     */
    public static function get(iterable $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array instanceof Traversable ? iterator_to_array($array) : $array;
        }

        if (is_array($array) && array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (! is_string($key) || ! str_contains($key, '.')) {
            $array = $array instanceof Traversable ? iterator_to_array($array) : (array) $array;

            return $array[$key] ?? value($default);
        }

        $segments = explode('.', $key);
        $array = $array instanceof Traversable ? iterator_to_array($array) : (array) $array;

        foreach ($segments as $segment) {
            if (! is_array($array) || ! array_key_exists($segment, $array)) {
                return value($default);
            }

            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Filter items by a key/value pair.
     */
    public static function where(iterable $array, string $key, mixed $operator = null, mixed $value = null): array
    {
        if (func_num_args() === 2 || ($value === null && self::isOperator($operator) === false)) {
            $value = $operator;
            $operator = '=';
        }

        return self::filterByCallback($array, function (mixed $item) use ($key, $operator, $value): bool {
            $retrieved = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? $item->$key ?? null : null);

            return self::compare($retrieved, $operator, $value);
        });
    }

    /**
     * Flatten a nested array into dot-notation keys.
     */
    public static function dotKeys(array $arr): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($arr));
        $res = [];

        foreach ($iterator as $leaf) {
            $keys = [];

            foreach (range(0, $iterator->getDepth()) as $depth) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }

            $res[implode('.', $keys)] = $leaf;
        }

        return $res;
    }

    // -------------------------------------------------------------------------
    // Builder methods (all return NEW instances)
    // -------------------------------------------------------------------------

    /**
     * Apply a callback to each item and return a new Sequence with the results.
     */
    public function map(callable $callback): static
    {
        return $this->clone()->with(array_map($callback, $this->items));
    }

    /**
     * Filter items by a callback and return a new Sequence.
     */
    public function filter(callable $callback): static
    {
        return $this->clone()->with(array_filter($this->items, $callback));
    }

    /**
     * Reduce the collection to a single value.
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Pluck values by key, optionally keyed by another key.
     */
    public function pluck(string|array $value, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = is_array($item) ? self::get($item, $value) : (
                is_object($item) ? self::getData($item, $value) : null
            );

            if ($key !== null) {
                $itemKey = is_array($item) ? self::get($item, $key) : (
                    is_object($item) ? self::getData($item, $key) : null
                );
                $results[$itemKey] = $itemValue;
            } else {
                $results[] = $itemValue;
            }
        }

        return $this->clone()->with($results);
    }

    /**
     * Flatten a multi-dimensional array.
     */
    public function flatten(int|float $depth = INF): static
    {
        return $this->clone()->with(self::flattenArray($this->items, $depth));
    }

    /**
     * Collapse an array of arrays into a single array.
     */
    public function collapse(): static
    {
        $results = [];

        foreach ($this->items as $values) {
            if ($values instanceof Arrayable) {
                $values = $values->toArray();
            }

            if (is_array($values)) {
                $results[] = $values;
            }
        }

        return $this->clone()->with(array_merge([], ...$results));
    }

    /**
     * Return only unique items.
     */
    public function unique(?callable $callback = null): static
    {
        if ($callback === null) {
            return $this->clone()->with(array_values(array_unique($this->items, SORT_REGULAR)));
        }

        $exists = [];
        $results = [];

        foreach ($this->items as $key => $item) {
            $id = $callback($item, $key);

            if (! isset($exists[$id])) {
                $exists[$id] = true;
                $results[$key] = $item;
            }
        }

        return $this->clone()->with($results);
    }

    /**
     * Sort the items.
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        if ($callback === null) {
            sort($items);
        } else {
            usort($items, $callback);
        }

        return $this->clone()->with($items);
    }

    /**
     * Shuffle the items.
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);

        return $this->clone()->with($items);
    }

    /**
     * Merge items into the sequence.
     */
    public function merge(iterable $items): static
    {
        if ($items instanceof Traversable) {
            $items = iterator_to_array($items);
        }

        return $this->clone()->with(array_merge($this->items, $items));
    }

    /**
     * Union items with the sequence (existing keys take precedence).
     */
    public function union(iterable $items): static
    {
        if ($items instanceof Traversable) {
            $items = iterator_to_array($items);
        }

        return $this->clone()->with(array_merge($items, $this->items));
    }

    /**
     * Return all items except those with the specified keys.
     */
    public function except(mixed $keys): static
    {
        return $this->clone()->with(
            array_diff_key($this->items, array_flip((array) $keys))
        );
    }

    /**
     * Return only items with the specified keys.
     */
    public function only(mixed $keys): static
    {
        return $this->clone()->with(
            array_intersect_key($this->items, array_flip((array) $keys))
        );
    }

    // -------------------------------------------------------------------------
    // Instance utility methods
    // -------------------------------------------------------------------------

    private const array INSTANCE_DELEGATES = ['first', 'last'];

    /**
     * Delegate instance calls to private do* implementations.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, self::INSTANCE_DELEGATES, true)) {
            $impl = 'do' . ucfirst($method);

            return self::$impl($this->items, ...$parameters);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }

    /**
     * Delegate static calls to private do* implementations.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (in_array($method, self::INSTANCE_DELEGATES, true)) {
            $impl = 'do' . ucfirst($method);

            return self::$impl(...$parameters);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }

    /**
     * Get the items as a plain array.
     */
    public function value(): array
    {
        return $this->items;
    }

    /**
     * Check if the sequence is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if the sequence is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    // -------------------------------------------------------------------------
    // Interface implementations
    // -------------------------------------------------------------------------

    public function toArray(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Set the items on this instance (used by builder methods after cloning).
     *
     * @param array<int|string, mixed> $items
     */
    protected function with(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    private static function exists(iterable $array, int|string $key): bool
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }

        if (is_array($array)) {
            return array_key_exists($key, $array);
        }

        if ($array instanceof Traversable) {
            $array = iterator_to_array($array);

            return array_key_exists($key, $array);
        }

        return false;
    }

    private static function compare(mixed $value, string $operator, mixed $value2): bool
    {
        return match ($operator) {
            '=' => $value == $value2,
            '==' => $value == $value2,
            '!=' => $value != $value2,
            '<>' => $value != $value2,
            '<' => $value < $value2,
            '>' => $value > $value2,
            '<=' => $value <= $value2,
            '>=' => $value >= $value2,
            '===' => $value === $value2,
            '!==' => $value !== $value2,
            default => false,
        };
    }

    private static function isOperator(mixed $operator): bool
    {
        return in_array($operator, ['=', '==', '!=', '<>', '<', '>', '<=', '>=', '===', '!=='], true);
    }

    private static function flattenArray(array $array, int|float $depth): array
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof Arrayable ? $item->toArray() : $item;

            if (! is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                foreach ($item as $child) {
                    $result[] = $child;
                }
            } else {
                foreach (self::flattenArray($item, $depth - 1) as $child) {
                    $result[] = $child;
                }
            }
        }

        return $result;
    }

    private static function filterByCallback(iterable $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function getData(object $object, string|array $key): mixed
    {
        $keys = (array) $key;
        $value = $object;

        foreach ($keys as $segment) {
            if (! is_object($value) && ! is_array($value)) {
                return null;
            }

            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } elseif (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
