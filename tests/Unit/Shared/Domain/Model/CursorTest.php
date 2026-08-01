<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testCreateWithNextDirection(): void
    {
        $cursor = Cursor::create(
            CursorDirection::Next,
            ['title' => 'Alpha', 'id' => '0195f5a0-0000-7000-8000-000000000001'],
        );

        $this->assertInstanceOf(Cursor::class, $cursor);
        $this->assertSame(CursorDirection::Next, $cursor->getDirection());
    }

    public function testCreateWithPrevDirection(): void
    {
        $cursor = Cursor::create(
            CursorDirection::Prev,
            ['title' => 'Zulu', 'id' => '0195f5a0-0000-7000-8000-000000000099'],
        );

        $this->assertInstanceOf(Cursor::class, $cursor);
        $this->assertSame(CursorDirection::Prev, $cursor->getDirection());
    }

    public function testCreateWithEmptyValues(): void
    {
        $cursor = Cursor::create(CursorDirection::Next, []);

        $this->assertSame([], $cursor->getValues());
    }

    public function testGetDirectionReturnsCorrectDirection(): void
    {
        $cursor = Cursor::create(CursorDirection::Prev, ['id' => 'test']);

        $this->assertSame(CursorDirection::Prev, $cursor->getDirection());
    }

    public function testGetValuesReturnsCorrectValues(): void
    {
        $values = [
            'title' => 'Bravo',
            'created_at' => '2026-04-19T12:00:00+00:00',
            'id' => '0195f5a0-0000-7000-8000-000000000042',
        ];
        $cursor = Cursor::create(CursorDirection::Next, $values);

        $this->assertEquals($values, $cursor->getValues());
    }

    public function testValuesContainIdKeyForTiebreaker(): void
    {
        $cursor = Cursor::create(CursorDirection::Next, [
            'title' => 'Charlie',
            'id' => '0195f5a0-0000-7000-8000-000000000007',
        ]);

        $values = $cursor->getValues();
        $this->assertArrayHasKey('id', $values);
        $this->assertSame('0195f5a0-0000-7000-8000-000000000007', $values['id']);
    }
}
