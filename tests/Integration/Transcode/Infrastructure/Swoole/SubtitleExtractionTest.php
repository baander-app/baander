<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\NativeExecutionFixture;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SubtitleExtractionTest extends TestCase
{
    private string $directory;
    private NativeExecutionFixture $native;
    private TranscodeProcessPool $pool;
    private TranscodeJob $job;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-subtitles-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->native = new NativeExecutionFixture('baander.app:workers', bin2hex(random_bytes(16)));
        $this->pool = new TranscodeProcessPool($this->native->client, new NullLogger(), new JsonEncoder());
        $this->job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), $this->directory, Uuid::generate());
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->pool)) {
                $this->pool->shutdown();
            }
        } finally {
            if (isset($this->native)) {
                $this->native->close();
            }
            if (isset($this->directory)) {
                (new Filesystem())->remove($this->directory);
            }
        }
    }

    public function testDuplicateAndMissingLanguagesPublishDistinctGenerationLocalWebVtt(): void
    {
        $arguments = ['/usr/bin/ffmpeg', '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=size=32x32:rate=2'];
        foreach (['Dialogue', 'Commentary', 'Untagged'] as $index => $text) {
            $path = $this->directory.'/'.$index.'.srt';
            file_put_contents($path, "1\n00:00:10,000 --> 00:00:40,000\n".$text." crossing\n\n2\n00:00:15,000 --> 00:00:40,000\n".$text." late\n");
            array_push($arguments, '-i', $path);
        }
        $source = $this->directory.'/source.mkv';
        array_push($arguments, '-map', '0:v', '-map', '1:s', '-map', '2:s', '-map', '3:s',
            '-metadata:s:s:0', 'language=eng', '-metadata:s:s:1', 'language=eng',
            '-disposition:s:0', 'default', '-disposition:s:1', 'forced',
            '-c:v', 'ffv1', '-c:s', 'srt', '-t', '45', $source);
        $this->runProcess($arguments);
        $raw = json_decode($this->runProcess(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $source]), true, flags: JSON_THROW_ON_ERROR);
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertCount(3, $probe->subtitleStreams);
        self::assertSame(['eng', 'eng', 'und'], array_map(static fn ($track): string => $track->language, $probe->subtitleStreams));
        self::assertTrue($probe->subtitleStreams[0]->isDefault);
        self::assertTrue($probe->subtitleStreams[1]->isForced);
        foreach ($probe->subtitleStreams as $offset => $track) {
            $path = $this->directory.'/'.$track->trackKey().'/full.vtt';
            $key = $this->pool->extractSubtitles($this->job, $source, $track->index, $path, 2, 12.0);
            self::assertFileDoesNotExist($path, 'Only a confirmed successful helper may publish the final path.');
            $result = $this->finish($key);
            self::assertSame('ok', $result['status'], $result['data']);
            self::assertFileExists($path);
            self::assertFileDoesNotExist($path.'.part');
            self::assertStringContainsString(['Dialogue late', 'Commentary late', 'Untagged late'][$offset], file_get_contents($path));
            $packets = json_decode($this->runProcess(['/usr/bin/ffprobe', '-v', 'error', '-show_packets', '-show_entries', 'packet=pts_time,duration_time', '-of', 'json', $path]), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $packets['packets']);
            self::assertSame(0.0, (float) $packets['packets'][0]['pts_time']);
            self::assertSame(28.0, (float) $packets['packets'][0]['duration_time']);
            self::assertSame(3.0, (float) $packets['packets'][1]['pts_time']);
            self::assertSame(25.0, (float) $packets['packets'][1]['duration_time']);
        }
    }

    public function testFailedExtractionPublishesNoFinalOrPartialFile(): void
    {
        $source = $this->directory.'/source.srt';
        file_put_contents($source, "1\n00:00:01,000 --> 00:00:02,000\nCue\n");
        $path = $this->directory.'/s99/full.vtt';
        $key = $this->pool->extractSubtitles($this->job, $source, 99, $path, 1);
        $result = $this->finish($key);
        self::assertSame('error', $result['status']);
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path.'.part');
    }

    /** @return array{status: string, data: string} */
    private function finish(string $key): array
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $result = $this->pool->readResult($key);
            if ($result !== null) {
                return $result;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        self::fail('Subtitle native helper did not finish within ten seconds.');
    }

    /** @param list<string> $arguments */
    private function runProcess(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout(15);
        $process->mustRun();
        return $process->getOutput();
    }
}
