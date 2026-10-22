<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\HLS;

use App\Transcode\Infrastructure\HLS\WebVttTimeline;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebVttTimelineTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-vtt-timeline-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testSeekPreservesOverlappingCuesAndSettingsWhileDroppingExpiredCues(): void
    {
        $source = <<<'VTT'
WEBVTT

expired
00:00:01.000 --> 00:00:05.000
Gone

ends-at-seek
00:00:10.000 --> 00:00:12.000
Gone at boundary

crossing
00:00:10.000 --> 00:00:40.000 align:start position:25%
First line
Second line

starts-at-seek
00:00:12.000 --> 00:00:14.500
At boundary

later
00:15.000 --> 00:40.000
Later cue
VTT;
        file_put_contents($this->directory.'/source.vtt', $source);
        (new WebVttTimeline())->rebase($this->directory.'/source.vtt', $this->directory.'/local.vtt', 12.0);
        $expected = <<<'VTT'
WEBVTT

crossing
00:00:00.000 --> 00:00:28.000 align:start position:25%
First line
Second line

starts-at-seek
00:00:00.000 --> 00:00:02.500
At boundary

later
00:00:03.000 --> 00:00:28.000
Later cue
VTT;
        self::assertSame($expected."\n\n", file_get_contents($this->directory.'/local.vtt'));
        self::assertSame($source, file_get_contents($this->directory.'/source.vtt'));
    }

    public function testWholeFileLimitRejectsBeforeCreatingOutput(): void
    {
        $source = $this->directory.'/source.vtt';
        $stream = fopen($source, 'wb');
        self::assertIsResource($stream);
        self::assertTrue(ftruncate($stream, 16 * 1024 * 1024 + 1));
        fclose($stream);
        try {
            (new WebVttTimeline())->rebase($source, $this->directory.'/local.vtt', 0.0);
            self::fail('Oversized subtitle output was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('16 MiB', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->directory.'/local.vtt');
    }

    public function testOversizedCueLineRemovesPartialOutput(): void
    {
        file_put_contents($this->directory.'/source.vtt', "WEBVTT\n\n00:01.000 --> 00:02.000\n".str_repeat('x', 65537));
        try {
            (new WebVttTimeline())->rebase($this->directory.'/source.vtt', $this->directory.'/local.vtt', 0.0);
            self::fail('Oversized subtitle line was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('processing limits', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->directory.'/local.vtt');
    }

    public function testNegativeTimelineStartCannotProduceNegativeCues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebVttTimeline())->rebase($this->directory.'/source.vtt', $this->directory.'/local.vtt', -1.0);
    }
}
