<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Console;

use App\Transcode\Application\DTO\HardwareTranscodeDiagnosticsReport;
use App\Transcode\Application\Port\HardwareTranscodeDiagnosticsInterface;
use App\Transcode\Interface\Console\DebugHwTranscodeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugHwTranscodeCommandTest extends TestCase
{
    #[DataProvider('hardwareModes')]
    public function testRendersReportAndOnlyShowsHardwareDecoderSectionWhenEnabled(bool $hardware): void
    {
        $report = new HardwareTranscodeDiagnosticsReport(
            accelerator: $hardware ? 'nvenc' : 'none',
            encoder: $hardware ? 'hevc_nvenc' : 'libx265',
            decoder: '',
            hwaccelMethod: $hardware ? 'cuda' : '',
            hwaccelDevice: '',
            hwaccelOutputFormat: $hardware ? 'cuda' : '',
            isHardware: $hardware,
            bitrateMultiplier: 1.25,
            hwaccelFlags: $hardware ? '-hwaccel cuda' : '',
            decoderFlags: '',
            sourceDecoders: $hardware ? ['h264' => 'h264_cuvid', 'unknown' => ''] : [],
            sampleCommands: ['720p' => '/usr/local/bin/ffmpeg -y -i source.mkv'],
            acceleratorReference: [['none', 'libx265', 'libx264', '(none)', 'No', 'No']],
        );
        $diagnostics = $this->createMock(HardwareTranscodeDiagnosticsInterface::class);
        $diagnostics->expects(self::once())->method('inspect')->willReturn($report);
        $command = new DebugHwTranscodeCommand($diagnostics);
        self::assertSame('debug:hw-transcode', $command->getName());
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $output = $tester->getDisplay();
        foreach (['Resolved Encoder Profile', 'FFmpeg Input Flags', 'Sample FFmpeg Commands', 'Hardware Accelerator Reference', '(per-source)', '(auto)', '1.25', '(none — resolved per-source at encode time)', '720p (init segment, h264 source):', '/usr/local/bin/ffmpeg -y -i source.mkv'] as $text) {
            self::assertStringContainsString($text, $output);
        }
        if ($hardware) {
            self::assertStringContainsString('Decoder Resolution Per Source Codec', $output);
            self::assertStringContainsString('h264_cuvid', $output);
            self::assertStringContainsString('(passthrough)', $output);
        } else {
            self::assertStringNotContainsString('Decoder Resolution Per Source Codec', $output);
            self::assertStringContainsString('No (software)', $output);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function hardwareModes(): iterable
    {
        yield 'software' => [false];
        yield 'hardware' => [true];
    }
}
