<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Sequence;
use Illuminate\Contracts\Support\Arrayable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SequenceTest extends TestCase
{
    // =========================================================================
    // Static: first()
    // =========================================================================

    #[Test]
    public function first_returns_first_item_without_callback(): void
    {
        $this->assertSame('a', Sequence::first(['a', 'b', 'c']));
    }

    #[Test]
    public function first_returns_first_matching_item_with_callback(): void
    {
        $result = Sequence::first(['a', 'b', 'c'], fn (string $item) => $item > 'a');
        $this->assertSame('b', $result);
    }

    #[Test]
    public function first_returns_default_for_empty_array(): void
    {
        $this->assertNull(Sequence::first([]));
        $this->assertSame('fallback', Sequence::first([], default: 'fallback'));
    }

    #[Test]
    public function first_returns_default_when_no_match(): void
    {
        $this->assertSame('none', Sequence::first([1, 2, 3], fn ($i) => $i > 10, 'none'));
    }

    // =========================================================================
    // Static: wrap()
    // =========================================================================

    #[Test]
    public function wrap_wraps_scalar_in_array(): void
    {
        $this->assertSame(['foo'], Sequence::wrap('foo'));
        $this->assertSame([42], Sequence::wrap(42));
        $this->assertSame([null], Sequence::wrap(null));
        $this->assertSame([true], Sequence::wrap(true));
    }

    #[Test]
    public function wrap_returns_array_unchanged(): void
    {
        $arr = ['a', 'b'];
        $this->assertSame($arr, Sequence::wrap($arr));
    }

    #[Test]
    public function wrap_converts_arrayable_to_array(): void
    {
        $arrayable = new class implements Arrayable {
            public function toArray(): array
            {
                return ['from' => 'arrayable'];
            }
        };

        $this->assertSame(['from' => 'arrayable'], Sequence::wrap($arrayable));
    }

    #[Test]
    public function wrap_handles_empty_array(): void
    {
        $this->assertSame([], Sequence::wrap([]));
    }

    // =========================================================================
    // Static: last()
    // =========================================================================

    #[Test]
    public function last_returns_last_item_without_callback(): void
    {
        $this->assertSame('c', Sequence::last(['a', 'b', 'c']));
    }

    #[Test]
    public function last_returns_last_matching_item_with_callback(): void
    {
        $result = Sequence::last([1, 2, 3, 4, 5], fn ($i) => $i < 4);
        $this->assertSame(3, $result);
    }

    #[Test]
    public function last_returns_default_for_empty_array(): void
    {
        $this->assertNull(Sequence::last([]));
        $this->assertSame('fallback', Sequence::last([], default: 'fallback'));
    }

    #[Test]
    public function last_returns_default_when_no_match(): void
    {
        $this->assertSame(0, Sequence::last([1, 2, 3], fn ($i) => $i > 10, 0));
    }

    // =========================================================================
    // Static: has()
    // =========================================================================

    #[Test]
    public function has_checks_single_key(): void
    {
        $this->assertTrue(Sequence::has(['foo' => 'bar'], 'foo'));
        $this->assertFalse(Sequence::has(['foo' => 'bar'], 'baz'));
    }

    #[Test]
    public function has_checks_numeric_keys(): void
    {
        $this->assertTrue(Sequence::has(['a', 'b', 'c'], 1));
        $this->assertFalse(Sequence::has(['a', 'b', 'c'], 5));
    }

    #[Test]
    public function has_checks_multiple_keys(): void
    {
        $this->assertTrue(Sequence::has(['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c']));
        $this->assertFalse(Sequence::has(['a' => 1, 'b' => 2], ['a', 'c']));
    }

    #[Test]
    public function has_returns_false_for_empty_keys_array(): void
    {
        $this->assertFalse(Sequence::has(['a' => 1], []));
    }

    #[Test]
    public function has_distinguishes_null_values_from_missing_keys(): void
    {
        $this->assertTrue(Sequence::has(['foo' => null], 'foo'));
        $this->assertFalse(Sequence::has([], 'foo'));
    }

    // =========================================================================
    // Static: get()
    // =========================================================================

    #[Test]
    public function get_returns_item_by_key(): void
    {
        $this->assertSame('bar', Sequence::get(['foo' => 'bar'], 'foo'));
    }

    #[Test]
    public function get_returns_default_for_missing_key(): void
    {
        $this->assertNull(Sequence::get(['foo' => 'bar'], 'baz'));
        $this->assertSame('default', Sequence::get(['foo' => 'bar'], 'baz', 'default'));
    }

    #[Test]
    public function get_supports_dot_notation(): void
    {
        $data = ['foo' => ['bar' => ['baz' => 'deep']]];
        $this->assertSame('deep', Sequence::get($data, 'foo.bar.baz'));
    }

    #[Test]
    public function get_returns_default_for_missing_dot_notation_segment(): void
    {
        $data = ['foo' => ['bar' => 'value']];
        $this->assertNull(Sequence::get($data, 'foo.missing'));
        $this->assertSame('fallback', Sequence::get($data, 'foo.missing.baz', 'fallback'));
    }

    #[Test]
    public function get_with_null_key_returns_full_array(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $this->assertSame($data, Sequence::get($data, null));
    }

    #[Test]
    public function get_prefers_exact_key_over_dot_notation(): void
    {
        $data = ['foo.bar' => 'exact', 'foo' => ['bar' => 'nested']];
        $this->assertSame('exact', Sequence::get($data, 'foo.bar'));
    }

    // =========================================================================
    // Static: where()
    // =========================================================================

    #[Test]
    public function where_filters_by_equality(): void
    {
        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
            ['name' => 'Charlie', 'age' => 30],
        ];

        $result = Sequence::where($data, 'age', 30);
        $this->assertCount(2, $result);
        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('Charlie', $result[2]['name']);
    }

    #[Test]
    public function where_filters_with_operator(): void
    {
        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
            ['name' => 'Charlie', 'age' => 20],
        ];

        $result = Sequence::where($data, 'age', '>', 25);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    #[Test]
    public function where_with_less_than_operator(): void
    {
        $data = [
            ['value' => 1],
            ['value' => 5],
            ['value' => 10],
        ];

        $result = Sequence::where($data, 'value', '<=', 5);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function where_with_not_equal_operator(): void
    {
        $data = [
            ['status' => 'active'],
            ['status' => 'inactive'],
            ['status' => 'active'],
        ];

        $result = Sequence::where($data, 'status', '!=', 'active');
        $this->assertCount(1, $result);
        $this->assertSame('inactive', $result[1]['status']);
    }

    #[Test]
    public function where_returns_empty_for_no_matches(): void
    {
        $result = Sequence::where([['a' => 1]], 'a', 99);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // Static: dotKeys()
    // =========================================================================

    #[Test]
    public function dotKeys_flattens_nested_array(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ],
            'settings' => [
                'theme' => 'dark',
            ],
        ];

        $result = Sequence::dotKeys($data);
        $this->assertSame('Alice', $result['user.name']);
        $this->assertSame('alice@example.com', $result['user.email']);
        $this->assertSame('dark', $result['settings.theme']);
    }

    #[Test]
    public function dotKeys_handles_deeply_nested(): void
    {
        $data = ['a' => ['b' => ['c' => 'deep']]];
        $result = Sequence::dotKeys($data);
        $this->assertSame('deep', $result['a.b.c']);
    }

    #[Test]
    public function dotKeys_handles_empty_array(): void
    {
        $this->assertSame([], Sequence::dotKeys([]));
    }

    // =========================================================================
    // Builder: make()
    // =========================================================================

    #[Test]
    public function make_creates_empty_sequence(): void
    {
        $seq = Sequence::make();
        $this->assertSame([], $seq->value());
        $this->assertTrue($seq->isEmpty());
    }

    #[Test]
    public function make_creates_sequence_from_array(): void
    {
        $seq = Sequence::make(['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $seq->value());
    }

    #[Test]
    public function make_creates_sequence_from_arrayable(): void
    {
        $arrayable = new class implements Arrayable {
            public function toArray(): array
            {
                return [1, 2, 3];
            }
        };

        $seq = Sequence::make($arrayable);
        $this->assertSame([1, 2, 3], $seq->value());
    }

    // =========================================================================
    // Builder: map, filter, reduce
    // =========================================================================

    #[Test]
    public function map_transforms_items(): void
    {
        $seq = Sequence::make([1, 2, 3]);
        $result = $seq->map(fn (int $i) => $i * 2);
        $this->assertSame([2, 4, 6], $result->value());
    }

    #[Test]
    public function filter_removes_items(): void
    {
        $seq = Sequence::make([1, 2, 3, 4]);
        $result = $seq->filter(fn (int $i) => $i % 2 === 0);
        $this->assertSame([1 => 2, 3 => 4], $result->value());
    }

    #[Test]
    public function reduce_reduces_to_single_value(): void
    {
        $seq = Sequence::make([1, 2, 3, 4]);
        $result = $seq->reduce(fn (int $carry, int $item) => $carry + $item, 0);
        $this->assertSame(10, $result);
    }

    // =========================================================================
    // Builder: pluck, flatten, collapse
    // =========================================================================

    #[Test]
    public function pluck_extracts_values(): void
    {
        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];

        $result = Sequence::make($data)->pluck('name');
        $this->assertSame(['Alice', 'Bob'], $result->value());
    }

    #[Test]
    public function pluck_with_key(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $result = Sequence::make($data)->pluck('name', 'id');
        $this->assertSame([1 => 'Alice', 2 => 'Bob'], $result->value());
    }

    #[Test]
    public function flatten_flattens_nested_array(): void
    {
        $data = [[1, 2], [3, 4], [5]];
        $result = Sequence::make($data)->flatten();
        $this->assertSame([1, 2, 3, 4, 5], $result->value());
    }

    #[Test]
    public function flatten_with_depth_limit(): void
    {
        $data = [[1, [2, [3]]], [4]];
        $result = Sequence::make($data)->flatten(1);
        $this->assertSame([1, [2, [3]], 4], $result->value());
    }

    #[Test]
    public function collapse_merges_arrays(): void
    {
        $data = [[1, 2], [3, 4], [5]];
        $result = Sequence::make($data)->collapse();
        $this->assertSame([1, 2, 3, 4, 5], $result->value());
    }

    // =========================================================================
    // Builder: unique, sort, shuffle
    // =========================================================================

    #[Test]
    public function unique_removes_duplicates(): void
    {
        $result = Sequence::make([1, 2, 2, 3, 3, 3])->unique();
        $this->assertSame([1, 2, 3], $result->value());
    }

    #[Test]
    public function unique_with_callback(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 1, 'name' => 'Alice Dup'],
        ];

        $result = Sequence::make($data)->unique(fn ($item) => $item['id']);
        $this->assertCount(2, $result->value());
        $this->assertSame('Alice', $result->value()[0]['name']);
    }

    #[Test]
    public function sort_sorts_items(): void
    {
        $result = Sequence::make([3, 1, 2])->sort();
        $this->assertSame([1, 2, 3], $result->value());
    }

    #[Test]
    public function sort_with_callback(): void
    {
        $result = Sequence::make([3, 1, 2])->sort(fn ($a, $b) => $b - $a);
        $this->assertSame([3, 2, 1], $result->value());
    }

    #[Test]
    public function shuffle_preserves_all_items(): void
    {
        $seq = Sequence::make([1, 2, 3, 4, 5]);
        $result = $seq->shuffle();
        $this->assertCount(5, $result->value());
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], $result->value());
    }

    #[Test]
    public function shuffle_is_immutable(): void
    {
        $seq = Sequence::make([1, 2, 3]);
        $result = $seq->shuffle();
        $this->assertNotSame($seq, $result);
        $this->assertSame([1, 2, 3], $seq->value());
    }

    // =========================================================================
    // Builder: merge, union, except, only
    // =========================================================================

    #[Test]
    public function merge_combines_arrays(): void
    {
        $result = Sequence::make(['a', 'b'])->merge(['c', 'd']);
        $this->assertSame(['a', 'b', 'c', 'd'], $result->value());
    }

    #[Test]
    public function merge_overwrites_numeric_keys(): void
    {
        $result = Sequence::make([0 => 'a', 1 => 'b'])->merge([0 => 'c']);
        $this->assertSame(['a', 'b', 'c'], $result->value());
    }

    #[Test]
    public function union_preserves_original_keys(): void
    {
        $result = Sequence::make(['a' => 1, 'b' => 2])->union(['b' => 99, 'c' => 3]);
        $this->assertSame(['b' => 2, 'c' => 3, 'a' => 1], $result->value());
    }

    #[Test]
    public function except_removes_keys(): void
    {
        $result = Sequence::make(['a' => 1, 'b' => 2, 'c' => 3])->except('b');
        $this->assertSame(['a' => 1, 'c' => 3], $result->value());
    }

    #[Test]
    public function except_accepts_array_of_keys(): void
    {
        $result = Sequence::make(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4])->except(['a', 'd']);
        $this->assertSame(['b' => 2, 'c' => 3], $result->value());
    }

    #[Test]
    public function only_keeps_specified_keys(): void
    {
        $result = Sequence::make(['a' => 1, 'b' => 2, 'c' => 3])->only('b');
        $this->assertSame(['b' => 2], $result->value());
    }

    #[Test]
    public function only_accepts_array_of_keys(): void
    {
        $result = Sequence::make(['a' => 1, 'b' => 2, 'c' => 3])->only(['a', 'c']);
        $this->assertSame(['a' => 1, 'c' => 3], $result->value());
    }

    // =========================================================================
    // Builder: chaining and immutability
    // =========================================================================

    #[Test]
    public function builder_methods_return_new_instances(): void
    {
        $original = Sequence::make([1, 2, 3]);
        $mapped = $original->map(fn (int $i) => $i * 10);

        $this->assertNotSame($original, $mapped);
        $this->assertSame([1, 2, 3], $original->value());
        $this->assertSame([10, 20, 30], $mapped->value());
    }

    #[Test]
    public function builder_supports_method_chaining(): void
    {
        $result = Sequence::make([1, 2, 3, 4, 5])
            ->filter(fn (int $i) => $i > 2)
            ->map(fn (int $i) => $i * 2)
            ->sort();

        $this->assertSame([6, 8, 10], $result->value());
    }

    #[Test]
    public function original_is_unchanged_after_chain(): void
    {
        $original = Sequence::make([3, 1, 2]);
        $original->filter(fn () => false)->map(fn () => null);

        $this->assertSame([3, 1, 2], $original->value());
    }

    // =========================================================================
    // Instance methods: first, last, isEmpty, isNotEmpty
    // =========================================================================

    #[Test]
    public function instance_first_returns_first_item(): void
    {
        $seq = Sequence::make(['x', 'y', 'z']);
        $this->assertSame('x', $seq->first());
    }

    #[Test]
    public function instance_first_with_callback(): void
    {
        $seq = Sequence::make([1, 2, 3, 4]);
        $this->assertSame(3, $seq->first(fn ($i) => $i > 2));
    }

    #[Test]
    public function instance_last_returns_last_item(): void
    {
        $seq = Sequence::make(['x', 'y', 'z']);
        $this->assertSame('z', $seq->last());
    }

    #[Test]
    public function is_empty_and_is_not_empty(): void
    {
        $this->assertTrue(Sequence::make()->isEmpty());
        $this->assertFalse(Sequence::make()->isNotEmpty());
        $this->assertFalse(Sequence::make([1])->isEmpty());
        $this->assertTrue(Sequence::make([1])->isNotEmpty());
    }

    // =========================================================================
    // Interfaces: count, foreach, jsonSerialize, toArray
    // =========================================================================

    #[Test]
    public function count_returns_item_count(): void
    {
        $this->assertSame(0, count(Sequence::make()));
        $this->assertSame(3, count(Sequence::make([1, 2, 3])));
    }

    #[Test]
    public function is_iterable_with_foreach(): void
    {
        $seq = Sequence::make(['a', 'b', 'c']);
        $collected = [];

        foreach ($seq as $key => $value) {
            $collected[$key] = $value;
        }

        $this->assertSame(['a', 'b', 'c'], $collected);
    }

    #[Test]
    public function json_serializable_returns_items(): void
    {
        $seq = Sequence::make(['foo' => 'bar']);
        $this->assertSame('{"foo":"bar"}', json_encode($seq));
    }

    #[Test]
    public function to_array_returns_items(): void
    {
        $items = ['foo' => 'bar', 'baz' => 123];
        $seq = Sequence::make($items);
        $this->assertSame($items, $seq->toArray());
    }

    #[Test]
    public function json_serialize_preserves_nested_arrays(): void
    {
        $items = ['users' => ['Alice', 'Bob']];
        $seq = Sequence::make($items);
        $this->assertSame($items, $seq->jsonSerialize());
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    #[Test]
    public function first_with_traversable(): void
    {
        $iterator = new \ArrayIterator(['first', 'second', 'third']);
        $this->assertSame('first', Sequence::first($iterator));
    }

    #[Test]
    public function has_with_traversable(): void
    {
        $iterator = new \ArrayIterator(['a' => 1, 'b' => 2]);
        $this->assertTrue(Sequence::has($iterator, 'a'));
        $this->assertFalse(Sequence::has($iterator, 'c'));
    }

    #[Test]
    public function get_with_traversable_and_dot_notation(): void
    {
        $iterator = new \ArrayIterator(['foo' => ['bar' => 'baz']]);
        $this->assertSame('baz', Sequence::get($iterator, 'foo.bar'));
    }

    #[Test]
    public function collapse_on_empty_sequence(): void
    {
        $this->assertSame([], Sequence::make()->collapse()->value());
    }

    #[Test]
    public function except_on_empty_sequence(): void
    {
        $this->assertSame([], Sequence::make()->except('foo')->value());
    }

    #[Test]
    public function only_on_empty_sequence(): void
    {
        $this->assertSame([], Sequence::make()->only('foo')->value());
    }

    #[Test]
    public function wrap_with_objects_and_closures(): void
    {
        $obj = new \stdClass;
        $obj->foo = 'bar';
        $result = Sequence::wrap($obj);
        $this->assertCount(1, $result);
        $this->assertSame($obj, $result[0]);
    }

    #[Test]
    public function get_with_numeric_key(): void
    {
        $data = [10, 20, 30];
        $this->assertSame(20, Sequence::get($data, 1));
        $this->assertNull(Sequence::get($data, 99));
    }

    #[Test]
    public function get_default_callable_is_called(): void
    {
        $called = false;
        $result = Sequence::get([], 'missing', function () use (&$called) {
            $called = true;

            return 'computed';
        });

        $this->assertTrue($called);
        $this->assertSame('computed', $result);
    }

    #[Test]
    public function first_default_callable_is_called(): void
    {
        $called = false;
        $result = Sequence::first([], null, function () use (&$called) {
            $called = true;

            return 'computed';
        });

        $this->assertTrue($called);
        $this->assertSame('computed', $result);
    }
}
