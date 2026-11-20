<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\TerminalLogGuard;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class TerminalLogGuardTest extends TestCase
{
    public function testOnlyTerminalOutputIsSuspendedAndOriginalHandlersAreRestored(): void
    {
        $terminal = new class('php://stderr') extends StreamHandler {
            public int $seen = 0;

            public function handle(LogRecord $record): bool
            {
                ++$this->seen;

                return false;
            }
        };
        $file = new TestHandler();
        $logger = new Logger('test', [$terminal, $file]);
        $guard = new TerminalLogGuard([$logger]);
        $guard->begin();
        $guard->begin();
        $logger->warning('Still recorded while the viewer owns the terminal');
        self::assertSame(0, $terminal->seen);
        self::assertCount(1, $file->getRecords());
        $guard->end();
        $guard->end();
        $logger->warning('Terminal restored');
        self::assertSame(1, $terminal->seen);
        self::assertSame([$terminal, $file], $logger->getHandlers());
    }
}
