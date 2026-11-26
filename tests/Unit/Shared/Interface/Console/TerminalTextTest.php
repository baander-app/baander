<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Interface\Console\TerminalText;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\TableSeparator;

final class TerminalTextTest extends TestCase
{
    public function testClippingCountsDisplayCellsAndPreservesCombiningMarks(): void
    {
        self::assertSame('中文a', TerminalText::clip('中文ab', 5));
        self::assertSame("e\u{0301}", TerminalText::clip("e\u{0301}z", 1));
        self::assertSame('', TerminalText::clip('中文', 1));
    }

    public function testUntrustedRecordsCannotInjectTerminalControls(): void
    {
        self::assertSame('beforeafter', TerminalText::clean("before\033]52;c;ZXZpbA==\007\033[2Jafter"));
        self::assertSame('hello', TerminalText::clean("hello\033]52;c;evil"));
        self::assertSame('<error>literal</error>', TerminalText::clip('<error>literal</error>', 40));
    }

    public function testALiteralFormatsToItsTextWithoutControls(): void
    {
        $formatter = new OutputFormatter(true);

        self::assertSame('<href=https://baander.app>x</>', $formatter->format(TerminalText::literal('<href=https://baander.app>x</>')));
        self::assertSame('curl/8', $formatter->format(TerminalText::literal("\033]8;;https://baander.app\033\\curl/8")));
    }

    public function testLiteralRowsEscapeTextCellsAndKeepOtherCellsAndSeparators(): void
    {
        $separator = new TableSeparator();

        self::assertSame(
            [['\\<info\\>x\\</info\\>', 3, null], $separator],
            TerminalText::literalRows([["<info>x</info>\033[2J", 3, null], $separator]),
        );
    }
}
