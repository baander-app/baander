<?php

namespace App\Primitives;

use App\Primitives\Traits\ForwardsCalls;
use App\Primitives\Traits\ImmutableBuilder;
use Illuminate\Contracts\Support\Arrayable;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Traversable;

/**
 * Immutable array manipulation with fluent builder pattern.
 *
 * All dynamic methods can be called statically where the first
 * argument becomes the array: Sequence::map([1, 2], fn($n) => $n * 2) → Sequence([2, 4])
 *
 * @method self map(callable $callback) Map over items
 * @method self filter(callable $callback) Filter items
 * @method self flatten(int|float $depth = INF) Flatten nested arrays
 * @method self collapse() Collapse arrays of arrays
 * @method self unique(?callable $callback = null) Remove duplicates
 * @method self sort(?callable $callback = null) Sort items
 * @method self shuffle() Shuffle items
 * @method self merge(iterable $items) Merge arrays
 * @method self union(iterable $items) Union arrays
 * @method self except(string|int|array $keys) Remove by keys
 * @method self only(string|int|array $keys) Keep only keys
 * @method mixed first(?callable $callback = null, mixed $default = null) First item
 * @method mixed last(?callable $callback = null, mixed $default = null) Last item
 * @method bool has(string|int|array $keys) Has keys
 * @method mixed get(string|int|null $key, mixed $default = null) Get by key (dot notation)
 * @method self where(string|callable $key, mixed $operator = null, mixed $value = null) Filter by key-value or callback
 * @method self values() Re-index array keys
 * @method array dotKeys() Flatten to dot notation
 * @method mixed reduce(callable $callback, mixed $initial = null) Reduce to single value
 * @method array pluck(string $value, string|null $key = null) Extract values
 *
 * @method static self map(iterable $items, callable $callback) Map over items
 * @method static self filter(iterable $items, callable $callback) Filter items
 * @method static self flatten(iterable $items, int|float $depth = INF) Flatten nested arrays
 * @method static self collapse(iterable $items) Collapse arrays of arrays
 * @method static self unique(iterable $items, ?callable $callback = null) Remove duplicates
 * @method static self sort(iterable $items, ?callable $callback = null) Sort items
 * @method static self shuffle(iterable $items) Shuffle items
 * @method static self merge(iterable $items, iterable $newItems) Merge arrays
 * @method static self union(iterable $items, iterable $newItems) Union arrays
 * @method static self except(iterable $items, string|int|array $keys) Remove by keys
 * @method static self only(iterable $items, string|int|array $keys) Keep only keys
 * @method static mixed first(iterable $items, ?callable $callback = null, mixed $default = null) First item
 * @method static mixed last(iterable $items, ?callable $callback = null, mixed $default = null) Last item
 * @method static bool has(iterable $items, string|int|array $keys) Has keys
 * @method static mixed get(iterable $items, string|int|null $key, mixed $default = null) Get by key
 * @method static self where(iterable $items, string|callable $key, mixed $operator = null, mixed $value = null) Filter by key-value or callback
 * @method static self values(iterable $items) Re-index array keys
 * @method static array dotKeys(iterable $items) Flatten to dot notation
 * @method static mixed reduce(iterable $items, callable $callback, mixed $initial = null) Reduce to single value
 * @method static array pluck(iterable $items, string $value, string|null $key = null) Extract values
 */
class Sequence implements Arrayable, \JsonSerializable, \Countable, \IteratorAggregate
{
    use ForwardsCalls;
    use ImmutableBuilder;

    protected function __construct(protected array $items = [])
    {
    }

    public static function make(iterable|Arrayable $array = []): static
    {
        if ($array instanceof Arrayable) {
            $array = $array->toArray();
        } elseif ($array instanceof Traversable) {
            $array = iterator_to_array($array);
        }

        return new static($array);
    }

    // ─── Static-Only ────────────────────────────────────────────────────────────

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

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function value(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    // ─── Interfaces ─────────────────────────────────────────────────────────────

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

    // ─── Magic Methods ──────────────────────────────────────────────────────────

    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (count($parameters) === 0) {
            throw new \BadMethodCallException("Method {$method}() requires at least one argument on " . static::class);
        }

        return static::make(array_shift($parameters))->{$method}(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        $impl = 'do' . ucfirst($method);

        if (! method_exists($this, $impl)) {
            static::throwBadMethodCallException($method);
        }

        return $this->$impl(...$parameters);
    }

    // ─── Private Implementation ──────────────────────────────────────────────────

    // ── Builders (return new Sequence) ────────────────────────────────────────

    private function doMap(callable $callback): static
    {
        return $this->clone()->with(array_map($callback, $this->items));
    }

    private function doFilter(callable $callback): static
    {
        return $this->clone()->with(array_filter($this->items, $callback));
    }

    private function doFlatten(int|float $depth = INF): static
    {
        return $this->clone()->with(self::flattenArray($this->items, $depth));
    }

    private function doCollapse(): static
    {
        return $this->clone()->with(self::computeCollapse($this->items));
    }

    private function doUnique(?callable $callback = null): static
    {
        if ($callback === null) {
            return $this->clone()->with(array_values(array_unique($this->items, SORT_REGULAR)));
        }

        return $this->clone()->with(self::doUniqueByCallback($this->items, $callback));
    }

    private function doSort(?callable $callback = null): static
    {
        $items = $this->items;

        if ($callback === null) {
            sort($items);
        } else {
            usort($items, $callback);
        }

        return $this->clone()->with($items);
    }

    private function doShuffle(): static
    {
        $items = $this->items;
        shuffle($items);

        return $this->clone()->with($items);
    }

    private function doMerge(iterable $newItems): static
    {
        if ($newItems instanceof Traversable) {
            $newItems = iterator_to_array($newItems);
        }

        return $this->clone()->with(array_merge($this->items, $newItems));
    }

    private function doUnion(iterable $newItems): static
    {
        if ($newItems instanceof Traversable) {
            $newItems = iterator_to_array($newItems);
        }

        return $this->clone()->with(array_merge($newItems, $this->items));
    }

    private function doExcept(string|int|array $keys): static
    {
        return $this->clone()->with(array_diff_key($this->items, array_flip((array) $keys)));
    }

    private function doOnly(string|int|array $keys): static
    {
        return $this->clone()->with(array_intersect_key($this->items, array_flip((array) $keys)));
    }

    // ── Inspectors (return primitives) ─────────────────────────────────────────

    private function doFirst(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($this->items)) {
                return value($default);
            }

            foreach ($this->items as $item) {
                return $item;
            }

            return value($default);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    private function doLast(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($this->items)) {
                return value($default);
            }

            $result = null;
            $hasResult = false;

            foreach ($this->items as $item) {
                $result = $item;
                $hasResult = true;
            }

            return $hasResult ? $result : value($default);
        }

        $result = value($default);

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                $result = $value;
            }
        }

        return $result;
    }

    private function doHas(string|int|array $keys): bool
    {
        $keys = (array) $keys;

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $this->items)) {
                return false;
            }
        }

        return true;
    }

    private function doGet(string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->items;
        }

        if (is_array($this->items) && array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        if (! is_string($key) || ! str_contains($key, '.')) {
            return $this->items[$key] ?? value($default);
        }

        $segments = explode('.', $key);
        $array = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($array) || ! array_key_exists($segment, $array)) {
                return value($default);
            }

            $array = $array[$segment];
        }

        return $array;
    }

    private function doWhere(string|callable $key, mixed $operator = null, mixed $value = null): static
    {
        if (! is_string($key)) {
            return $this->clone()->with(self::filterByCallback($this->items, $key));
        }

        if (func_num_args() === 2 || ($value === null && self::isOperator($operator) === false)) {
            $value = $operator;
            $operator = '=';
        }

        return $this->clone()->with(self::filterByCallback($this->items, function (mixed $item) use ($key, $operator, $value): bool {
            $retrieved = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? $item->$key ?? null : null);

            return self::compare($retrieved, $operator, $value);
        }));
    }

    private function doValues(): static
    {
        return $this->clone()->with(array_values($this->items));
    }

    private function doDotKeys(): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($this->items));
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

    private function doReduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    private function doPluck(string $value, ?string $key = null): array
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = is_array($item) ? ($item[$value] ?? null) : (
                is_object($item) ? $item->$value ?? null : null
            );

            if ($key !== null) {
                $itemKey = is_array($item) ? ($item[$key] ?? null) : (
                    is_object($item) ? $item->$key ?? null : null
                );
                $results[$itemKey] = $itemValue;
            } else {
                $results[] = $itemValue;
            }
        }

        return $results;
    }

    // ─── Private Helpers ────────────────────────────────────────────────────────

    protected function with(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    private static function isOperator(mixed $operator): bool
    {
        return in_array($operator, ['=', '==', '!=', '<>', '<', '>', '<=', '>=', '===', '!=='], true);
    }

    private static function compare(mixed $value, string $operator, mixed $value2): bool
    {
        return match ($operator) {
            '=', '==' => $value == $value2,
            '!=', '<>' => $value != $value2,
            '<' => $value < $value2,
            '>' => $value > $value2,
            '<=' => $value <= $value2,
            '>=' => $value >= $value2,
            '===' => $value === $value2,
            '!==' => $value !== $value2,
            default => false,
        };
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

    private static function computeCollapse(array $items): array
    {
        $results = [];

        foreach ($items as $values) {
            if ($values instanceof Arrayable) {
                $values = $values->toArray();
            }

            if (is_array($values)) {
                $results[] = $values;
            }
        }

        return array_merge([], ...$results);
    }

    private static function doUniqueByCallback(array $items, callable $callback): array
    {
        $exists = [];
        $results = [];

        foreach ($items as $key => $item) {
            $id = $callback($item, $key);

            if (! isset($exists[$id])) {
                $exists[$id] = true;
                $results[$key] = $item;
            }
        }

        return $results;
    }
}
