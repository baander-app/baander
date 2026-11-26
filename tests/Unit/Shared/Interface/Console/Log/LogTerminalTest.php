<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console\Log;

use App\Shared\Interface\Console\Log\LogTerminalInput;
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
}
