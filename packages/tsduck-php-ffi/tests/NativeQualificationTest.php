<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\DuckContext;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Info;
use Tsduck\Report\AbstractSyncReport;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\SectionFile;
use Tsduck\Util\InBuffer;

final class NativeQualificationTest extends TestCase
{
    public function testInstalledBindingReportsItsVersion(): void
    {
        self::assertGreaterThan(0, Info::intVersion());
        self::assertNotSame('', Info::version());
        self::assertTrue(LibTSDuck::isLoaded());
    }

    public function testSectionBinaryRoundTripAndNativeLifetime(): void
    {
        $context = new DuckContext(NullReport::getInstance());
        $sections = new SectionFile($context);

        try {
            // A short private section: table id, section length, three payload bytes.
            $binary = "\x80\x30\x03\x01\x02\x03";
            $sections->fromBinary($binary);

            self::assertSame(1, $sections->sectionsCount());
            self::assertSame(strlen($binary), $sections->binarySize());
            self::assertSame($binary, $sections->toBinary());

            $sections->clear();
            self::assertSame(0, $sections->sectionsCount());
            self::assertSame('', $sections->toBinary());
            $sections->close();
            $sections->close();
            self::assertTrue($sections->isClosed());

            $this->expectException(TsduckException::class);
            $sections->sectionsCount();
        } finally {
            $sections->close();
            $context->close();
        }
    }

    public function testNativeReportCallsPhpWithUnicodeAfterGarbageCollection(): void
    {
        $report = new class(Report::Debug) extends AbstractSyncReport {
            /** @var list<array{int, string}> */
            public array $messages = [];

            public function log(int $severity, string $message): void
            {
                $this->messages[] = [$severity, $message];
            }
        };

        try {
            $ffi = LibTSDuck::getInstance();
            $message = 'TSDuck callback: æøå 日本語';
            $buffer = new InBuffer($ffi);
            $buffer->append($message);
            gc_collect_cycles();

            // Calling the native entrypoint proves the C-to-PHP trampoline runs;
            // calling the overridden log() method would only exercise PHP.
            $ffi->tspyLogReport($report->nativePointer(), Report::Warning, $buffer->getBuffer(), $buffer->getSize());

            self::assertSame([[Report::Warning, $message]], $report->messages);
        } finally {
            $report->close();
        }
    }
}
