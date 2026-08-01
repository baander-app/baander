<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Interface\Resource\AbstractResource;
use PHPUnit\Framework\TestCase;

final class AbstractResourceTest extends TestCase
{
    public function testFromTransformsSingleItem(): void
    {
        $result = StubResource::from(new StubModel('Alice', 30));

        $this->assertSame([
            'name' => 'Alice',
            'age' => 30,
        ], $result);
    }

    public function testCollectionTransformsMultipleItems(): void
    {
        $items = [
            new StubModel('Alice', 30),
            new StubModel('Bob', 25),
        ];

        $result = StubResource::collection($items);

        $this->assertSame([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ], $result);
    }

    public function testCollectionHandlesEmptyArray(): void
    {
        $result = StubResource::collection([]);

        $this->assertSame([], $result);
    }

    public function testCollectionHandlesIterator(): void
    {
        $items = new \ArrayIterator([
            new StubModel('Alice', 30),
        ]);

        $result = StubResource::collection($items);

        $this->assertSame([
            ['name' => 'Alice', 'age' => 30],
        ], $result);
    }

    public function testPaginateReturnsPaginatedResponse(): void
    {
        $items = [
            new StubModel('Alice', 30),
            new StubModel('Bob', 25),
        ];

        $result = StubResource::paginate($items, 1, 5, 2, 10);

        $this->assertInstanceOf(PaginatedResponse::class, $result);

        $array = $result->toArray();
        $this->assertSame([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ], $array['data']);
        $this->assertSame(1, $array['meta']['current_page']);
        $this->assertSame(5, $array['meta']['last_page']);
        $this->assertSame(2, $array['meta']['per_page']);
        $this->assertSame(10, $array['meta']['total']);
    }
}

// --- Test doubles ---

final class StubModel
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {
    }
}

final class StubResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof StubModel);

        return [
            'name' => $source->name,
            'age' => $source->age,
        ];
    }
}
