<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\CursorPage;
use PHPUnit\Framework\TestCase;

final class CursorPageTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $page = new CursorPage(
            items: ['item1', 'item2'],
            nextCursor: 'eyJk...cursor',
            prevCursor: 'eyJk...prev',
            hasNextPage: true,
            hasPreviousPage: true,
            total: 100,
            staleCursor: false,
            perPage: 20,
        );

        $this->assertInstanceOf(CursorPage::class, $page);
    }

    public function testFirstPageWithNullCursors(): void
    {
        $page = new CursorPage(
            items: ['item1', 'item2'],
            nextCursor: 'eyJk...next',
            prevCursor: null,
            hasNextPage: true,
            hasPreviousPage: false,
            total: 50,
            staleCursor: false,
            perPage: 20,
        );

        $array = $page->toArray();
        $this->assertNull($array['prev_cursor']);
        $this->assertFalse($array['has_previous_page']);
        $this->assertNotNull($array['next_cursor']);
    }

    public function testMiddlePageWithBothDirections(): void
    {
        $page = new CursorPage(
            items: ['item3', 'item4'],
            nextCursor: 'eyJk...next',
            prevCursor: 'eyJk...prev',
            hasNextPage: true,
            hasPreviousPage: true,
            total: 100,
            staleCursor: false,
            perPage: 2,
        );

        $array = $page->toArray();
        $this->assertTrue($array['has_next_page']);
        $this->assertTrue($array['has_previous_page']);
        $this->assertNotNull($array['next_cursor']);
        $this->assertNotNull($array['prev_cursor']);
    }

    public function testWithEmptyItemsArray(): void
    {
        $page = new CursorPage(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 0,
            staleCursor: false,
            perPage: 20,
        );

        $array = $page->toArray();
        $this->assertSame([], $array['items']);
        $this->assertSame(0, $array['total']);
    }

    public function testWithStaleCursorTrue(): void
    {
        $page = new CursorPage(
            items: ['item1'],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 10,
            staleCursor: true,
            perPage: 20,
        );

        $array = $page->toArray();
        $this->assertTrue($array['stale_cursor']);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $items = ['song_a', 'song_b'];
        $page = new CursorPage(
            items: $items,
            nextCursor: 'next_enc',
            prevCursor: 'prev_enc',
            hasNextPage: true,
            hasPreviousPage: false,
            total: 42,
            staleCursor: false,
            perPage: 15,
        );

        $array = $page->toArray();

        $this->assertSame($items, $array['items']);
        $this->assertSame('next_enc', $array['next_cursor']);
        $this->assertSame('prev_enc', $array['prev_cursor']);
        $this->assertTrue($array['has_next_page']);
        $this->assertFalse($array['has_previous_page']);
        $this->assertSame(42, $array['total']);
        $this->assertFalse($array['stale_cursor']);
        $this->assertSame(15, $array['per_page']);
    }

    public function testToArrayWithNullCursorsReturnsNullValues(): void
    {
        $page = new CursorPage(
            items: [],
            nextCursor: null,
            prevCursor: null,
            hasNextPage: false,
            hasPreviousPage: false,
            total: 0,
            staleCursor: false,
            perPage: 10,
        );

        $array = $page->toArray();

        $this->assertNull($array['next_cursor']);
        $this->assertNull($array['prev_cursor']);
        $this->assertArrayHasKey('next_cursor', $array);
        $this->assertArrayHasKey('prev_cursor', $array);
    }
}
