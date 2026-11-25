<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\LoginBlock;
use PHPUnit\Framework\TestCase;

final class LoginBlockTest extends TestCase
{
    public function testEveryFieldIsCutTo255Characters(): void
    {
        $block = LoginBlock::create(
            ipAddress: str_repeat('1', 300),
            email: str_repeat('e', 300),
            fieldValue: str_repeat('f', 300),
            userAgent: str_repeat('u', 300),
        );

        self::assertSame(str_repeat('1', 255), $block->getIpAddress());
        self::assertSame(str_repeat('e', 255), $block->getEmail());
        self::assertSame(str_repeat('f', 255), $block->getFieldValue());
        self::assertSame(str_repeat('u', 255), $block->getUserAgent());
    }

    public function testACutFallsBetweenCharactersNotBytes(): void
    {
        $block = LoginBlock::create('192.0.2.1', 'bot@baander.app', str_repeat('ø', 300), str_repeat('日', 300));

        self::assertSame(str_repeat('ø', 255), $block->getFieldValue());
        self::assertSame(str_repeat('日', 255), $block->getUserAgent());
    }

    /** PostgreSQL text holds neither a NUL byte nor invalid UTF-8; each such byte becomes `?` before the cut. */
    public function testNulBytesAndInvalidUtf8AreReplacedBeforeTheCut(): void
    {
        $block = LoginBlock::create('192.0.2.1', "bot\0@baander.app", "\0" . str_repeat('ø', 300), "Bot\xFF/1.0 \xC3\x28");

        self::assertSame('bot?@baander.app', $block->getEmail());
        self::assertSame('?' . str_repeat('ø', 254), $block->getFieldValue());
        self::assertSame('Bot?/1.0 ?(', $block->getUserAgent());
    }

    public function testValuesWithinTheBoundAreKept(): void
    {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64) ' . str_repeat('x', 223);
        $block = LoginBlock::create('2001:db8::1', 'bot@baander.app', 'filled', $agent);

        self::assertSame('2001:db8::1', $block->getIpAddress());
        self::assertSame('bot@baander.app', $block->getEmail());
        self::assertSame('filled', $block->getFieldValue());
        self::assertSame($agent, $block->getUserAgent());
    }

    public function testBlocksRecordedMoreThanThirtyDaysEarlierArePastRetention(): void
    {
        $block = LoginBlock::create('192.0.2.1', 'bot@baander.app', 'filled', 'curl/8');

        self::assertEquals($block->getCreatedAt()->modify('-30 days'), $block->retainedSince());
    }
}
