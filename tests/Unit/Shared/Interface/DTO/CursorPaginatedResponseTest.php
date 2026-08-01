<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Interface\DTO;

use App\Shared\Domain\Model\CursorPage;
use App\Shared\Interface\DTO\CursorPaginatedResponse;
use PHPUnit\Framework\TestCase;

final class CursorPaginatedResponseTest extends TestCase
{
    public function testFromPageCreatesCorrectDtoFromCursorPageWithData(): void
    {
        $page = new CursorPage(
            items: ['item1', 'item2'],
            nextCursor: 'next-abc',
            prevCursor: 'prev-xyz',
            hasNextPage: true,
            hasPreviousPage: false,
            total: 100,
            staleCursor: false,
            perPage: 50,
        );

        $response = CursorPaginatedResponse::fromPage($page, ['resource1', 'resource2']);

        $this->assertSame(['resource1', 'resource2'], $response->data);
        $this->assertSame('next-abc', $response->nextCursor);
        $this->assertSame('prev-xyz', $response->prevCursor);
        $this->assertTrue($response->hasNextPage);
        $this->assertFalse($response->hasPreviousPage);
        $this->assertSame(100, $response->total);
        $this->assertFalse($response->staleCursor);
        $this->assertSame(50, $response->perPage);
    }

    public function testToArrayReturnsCorrectJsonStructureWithAllMetaFields(): void
    {
        $page = new CursorPage(
            items: [],
            nextCursor: 'next-cursor',
            prevCursor: 'prev-cursor',
            hasNextPage: true,
            hasPreviousPage: true,
            total: 500,
            staleCursor: false,
            perPage: 25,
        );

        $response = CursorPaginatedResponse::fromPage($page, ['data']);
        $result = $response->toArray();

        $this->assertSame(['data'], $result['data']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('next-cursor', $result['meta']['next_cursor']);
        $this->assertSame('prev-cursor', $result['meta']['prev_cursor']);
        $this->assertTrue($result['meta']['has_next_page']);
        $this->assertTrue($result['meta']['has_previous_page']);
        $this->assertSame(500, $result['meta']['total']);
        $this->assertSame(25, $result['meta']['per_page']);
        $this->assertFalse($result['meta']['stale_cursor']);
    }

    public function testToArrayWithNullCursorsIncludesThemAsNull(): void
    {
        $page = new CursorPage(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 0,
            staleCursor: false,
            perPage: 50,
        );

        $response = CursorPaginatedResponse::fromPage($page, []);
        $result = $response->toArray();

        $this->assertNull($result['meta']['next_cursor']);
        $this->assertNull($result['meta']['prev_cursor']);
        $this->assertFalse($result['meta']['has_next_page']);
        $this->assertFalse($result['meta']['has_previous_page']);
    }

    public function testToArrayWithStaleCursorTrueIncludesStaleCursorInMeta(): void
    {
        $page = new CursorPage(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 100,
            staleCursor: true,
            perPage: 50,
        );

        $response = CursorPaginatedResponse::fromPage($page, []);
        $result = $response->toArray();

        $this->assertTrue($result['meta']['stale_cursor']);
    }

    public function testToArrayOnFirstPage(): void
    {
        $page = new CursorPage(
            items: ['item1', 'item2'],
            nextCursor: 'next-page',
            prevCursor: null,
            hasNextPage: true,
            hasPreviousPage: false,
            total: 200,
            staleCursor: false,
            perPage: 50,
        );

        $response = CursorPaginatedResponse::fromPage($page, ['item1', 'item2']);
        $result = $response->toArray();

        $this->assertTrue($result['meta']['has_next_page']);
        $this->assertFalse($result['meta']['has_previous_page']);
        $this->assertNotNull($result['meta']['next_cursor']);
        $this->assertNull($result['meta']['prev_cursor']);
    }

    public function testToArrayOnMiddlePage(): void
    {
        $page = new CursorPage(
            items: ['item3', 'item4'],
            nextCursor: 'next-page',
            prevCursor: 'prev-page',
            hasNextPage: true,
            hasPreviousPage: true,
            total: 200,
            staleCursor: false,
            perPage: 50,
        );

        $response = CursorPaginatedResponse::fromPage($page, ['item3', 'item4']);
        $result = $response->toArray();

        $this->assertTrue($result['meta']['has_next_page']);
        $this->assertTrue($result['meta']['has_previous_page']);
        $this->assertNotNull($result['meta']['next_cursor']);
        $this->assertNotNull($result['meta']['prev_cursor']);
    }
}
