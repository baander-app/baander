<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testCreateWithItems(): void
    {
        $items = ['item1', 'item2'];
        $result = SearchResult::create($items, 100, 0.95);

        $this->assertSame($items, $result->getItems());
        $this->assertSame(100, $result->getTotal());
        $this->assertSame(0.95, $result->getHighestScore());
        $this->assertFalse($result->isEmpty());
    }

    public function testCreateWithNoScore(): void
    {
        $result = SearchResult::create(['item'], 1);

        $this->assertSame(0.0, $result->getHighestScore());
    }

    public function testEmptyResult(): void
    {
        $result = SearchResult::empty();

        $this->assertSame([], $result->getItems());
        $this->assertSame(0, $result->getTotal());
        $this->assertSame(0.0, $result->getHighestScore());
        $this->assertTrue($result->isEmpty());
    }

    public function testEmptyItemsWithNonZeroTotal(): void
    {
        $result = SearchResult::create([], 5, 0.0);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(5, $result->getTotal());
    }

    public function testReadonlyClass(): void
    {
        $reflection = new \ReflectionClass(SearchResult::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
