<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Pagination\CursorResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class CursorPaginatorTest extends TestCase
{
    private CursorPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new CursorPaginator(
            new \App\Shared\Infrastructure\Pagination\CursorCodec(new JsonEncoder()),
        );
    }

    // ── CursorResult DTO tests ─────────────────────────────────────────────

    public function testCursorResultHoldsAllValues(): void
    {
        $cursor = Cursor::create(CursorDirection::Next, ['sort' => 'Title', 'id' => 'id-1']);
        $result = new CursorResult(
            items: ['item1', 'item2'],
            nextCursor: $cursor,
            prevCursor: null,
            hasNextPage: true,
            hasPreviousPage: false,
            total: 100,
            staleCursor: false,
            perPage: 20,
        );

        $this->assertSame(['item1', 'item2'], $result->items);
        $this->assertSame($cursor, $result->nextCursor);
        $this->assertNull($result->prevCursor);
        $this->assertTrue($result->hasNextPage);
        $this->assertFalse($result->hasPreviousPage);
        $this->assertSame(100, $result->total);
        $this->assertFalse($result->staleCursor);
        $this->assertSame(20, $result->perPage);
    }

    public function testCursorResultWithNullCursors(): void
    {
        $result = new CursorResult(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 0,
            staleCursor: false,
            perPage: 50,
        );

        $this->assertSame([], $result->items);
        $this->assertNull($result->nextCursor);
        $this->assertNull($result->prevCursor);
        $this->assertFalse($result->hasNextPage);
        $this->assertFalse($result->hasPreviousPage);
        $this->assertSame(0, $result->total);
        $this->assertFalse($result->staleCursor);
        $this->assertSame(50, $result->perPage);
    }

    public function testCursorResultWithStaleCursorTrue(): void
    {
        $result = new CursorResult(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 10,
            staleCursor: true,
            perPage: 20,
        );

        $this->assertTrue($result->staleCursor);
        $this->assertSame(10, $result->total);
    }

    public function testCursorResultWithBothCursorsSet(): void
    {
        $next = Cursor::create(CursorDirection::Next, ['sort' => 'M', 'id' => 'id-5']);
        $prev = Cursor::create(CursorDirection::Prev, ['sort' => 'A', 'id' => 'id-1']);

        $result = new CursorResult(
            items: ['a', 'b', 'c'],
            nextCursor: $next,
            prevCursor: $prev,
            hasNextPage: true,
            hasPreviousPage: true,
            total: 42,
            staleCursor: false,
            perPage: 3,
        );

        $this->assertSame(CursorDirection::Next, $result->nextCursor->getDirection());
        $this->assertSame(CursorDirection::Prev, $result->prevCursor->getDirection());
        $this->assertSame('M', $result->nextCursor->getValues()['sort']);
        $this->assertSame('A', $result->prevCursor->getValues()['sort']);
        $this->assertTrue($result->hasNextPage);
        $this->assertTrue($result->hasPreviousPage);
    }

    public function testCursorResultIsReadonly(): void
    {
        $result = new CursorResult(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 0,
            staleCursor: false,
            perPage: 10,
        );

        $reflection = new \ReflectionClass($result);
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }

    // ── CursorPaginator validation tests ───────────────────────────────────

    /**
     * Comprehensive cursor pagination logic testing requires a real database and
     * is covered by functional tests. These unit tests verify the parts that
     * can be tested without Doctrine infrastructure.
     */
    public function testPaginateThrowsInvalidArgumentExceptionForLimitZero(): void
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be at least 1');

        $this->paginator->paginate(
            $qb,
            's.title',
            's.id',
            null,
            0,
            static fn (object $item): array => ['sort' => '', 'id' => ''],
        );
    }

    public function testPaginateThrowsInvalidArgumentExceptionForNegativeLimit(): void
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be at least 1');

        $this->paginator->paginate(
            $qb,
            's.title',
            's.id',
            null,
            -5,
            static fn (object $item): array => ['sort' => '', 'id' => ''],
        );
    }
}
