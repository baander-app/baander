<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console\Log;

use App\Shared\Interface\Console\Log\LogTerminalInput;
use App\Shared\Interface\Console\Log\LogTerminalText;
use PHPUnit\Framework\TestCase;

final class LogTerminalTest extends TestCase
{
    public function testFragmentedKeysAndUnicode(): void
    {
        $input = new LogTerminalInput();
        self::assertSame([], $input->feed("\033["));
        self::assertSame(['down', 'j', 'page-up'], $input->feed("Bj\033[5~"));
        self::assertSame([], $input->feed("\xc3"));
        self::assertSame(['é'], $input->feed("\xa9"));
    }

    public function testClippingCountsDisplayCellsAndPreservesCombiningMarks(): void
    {
        self::assertSame('中文a', LogTerminalText::clip('中文ab', 5));
        self::assertSame("e\u{0301}", LogTerminalText::clip("e\u{0301}z", 1));
        self::assertSame('', LogTerminalText::clip('中文', 1));
    }

    public function testUntrustedRecordsCannotInjectTerminalControls(): void
    {
        self::assertSame('beforeafter', LogTerminalText::clean("before\033]52;c;ZXZpbA==\007\033[2Jafter"));
        self::assertSame('hello', LogTerminalText::clean("hello\033]52;c;evil"));
        self::assertSame('<error>literal</error>', LogTerminalText::clip('<error>literal</error>', 40));
    }
}
