<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Packaging;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProgressiveMediaPublicationTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $children = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-progressive-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->preserveArtifacts('before-cleanup');
        foreach (array_reverse($this->children) as $child) {
            if ($child->isRunning()) {
                $child->stop(1);
            }
        }
        $this->preserveArtifacts('after-stop');
        (new Filesystem())->remove($this->directory);
    }

    public function testHlsCompletesAndDurationLimitedDashMatchesCompletedPresentation(): void
    {
        self::assertTrue(is_executable('/usr/bin/ffmpeg'));
        self::assertTrue(is_executable('/usr/bin/ffprobe'));
        $source = $this->directory . '/source.mp4';
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i',
            'testsrc2=size=64x64:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-t', '20', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-g', '24', '-c:a', 'aac', $source]);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64000a');
        $arguments = $encoder->buildStreamArgs($source, $tier, '', $this->directory,
            audioFilters: 'aformat=channel_layouts=stereo', hasAudio: true);
        $input = array_search('-i', $arguments, true);
        self::assertIsInt($input);
        array_splice($arguments, $input, 0, ['-re']);
        $producer = $this->start($arguments);
        $reader = new PublishedPresentationReader();
        $this->until(static fn (): bool => $reader->read(dirname($source)) !== null,
            'No closed native presentation appeared.', $producer);
        self::assertFalse($reader->read($this->directory)?->complete);
        $base = $this->startHttpServer();
        $hlsUrl = $this->manifestUrl($base, 'master.m3u8');
        $dashUrl = $this->manifestUrl($base, 'manifest.mpd');
        $hls = $this->start($this->consumer($hlsUrl, 'hls-live'));
        $dash = $this->start($this->consumer($dashUrl, 'dash-live-prefix', 13));
        $dynamicOutput = $this->observeDynamicOutputs($producer, $reader);
        $this->waitForExit($dash);
        $this->waitForExit($hls);
        self::assertTrue($reader->read($this->directory)?->complete);
        $this->runMedia($this->consumer($hlsUrl, 'hls-complete'));
        $this->runMedia($this->consumer($dashUrl, 'dash-complete-prefix', 13));
        $this->runMedia($this->consumer($dashUrl, 'dash-complete'));
        $this->assertSameOutputs('hls-live', 'hls-complete');
        $this->assertSameOutputs('dash-live-prefix', 'dash-complete-prefix');
        $hlsCounts = $this->assertDecodedDuration('hls-live', 20, 480, 1);
        $prefixCounts = $this->assertDecodedDuration('dash-live-prefix', 13, 312, 0);
        $staticDashCounts = $this->assertDecodedDuration('dash-complete', 20, 480, 1);
        foreach (['hls-live', 'dash-live-prefix', 'dash-complete'] as $name) {
            $this->assertMonotonicPackets($this->directory . '/' . $name . '.framecrc');
        }
        $requests = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertNotEmpty($requests);
        foreach ($requests as $request) {
            self::assertSame(200, $request['status'], json_encode($request, JSON_THROW_ON_ERROR));
            self::assertTrue($request['closed']);
        }
        $dynamicMpd = array_values(array_filter($requests, static fn (array $request): bool =>
            $request['consumer'] === 'dash-live-prefix' && str_ends_with($request['path'], '/manifest.mpd') && !$request['complete']));
        self::assertGreaterThanOrEqual(2, count($dynamicMpd));
        self::assertLessThanOrEqual(18, count($dynamicMpd));
        self::assertGreaterThan(1, count(array_unique(array_column($dynamicMpd, 'segments'))));
        self::assertNotEmpty(array_filter($requests, static fn (array $request): bool =>
            $request['consumer'] === 'dash-live-prefix' && str_ends_with($request['path'], '/segment.m4s')
            && $request['index'] !== null && $request['index'] > 0 && !$request['complete']));
        self::assertSame([], glob($this->directory . '/*.tmp'));
        $receipt = [
            'sourceDurationSeconds' => 20,
            'hlsLiveCompletion' => 'natural-eof',
            'hlsFullContentQualified' => true,
            'dashStaticCompletion' => 'natural-eof',
            'dashStaticFullContentQualified' => true,
            'dashLiveCompletion' => 'explicit-output-duration-limit',
            'dashLivePrefixDurationSeconds' => 13,
            'dashLiveFullContentQualified' => false,
            'dashDynamicNaturalEofQualified' => false,
            'dashDynamicNaturalEofLimitation' => 'The FFmpeg DASH demuxer retains live mode after a static MPD refresh; interrupted reads do not preserve the decoder tail.',
            'hlsDecoded' => $hlsCounts,
            'dashLivePrefixDecoded' => $prefixCounts,
            'dashStaticDecoded' => $staticDashCounts,
            'dynamicMpdRequests' => count($dynamicMpd),
            'dynamicSegmentCounts' => array_values(array_unique(array_column($dynamicMpd, 'segments'))),
            'dynamicOutput' => $dynamicOutput,
            'httpRequests' => count($requests),
            'hlsFullOutputEqualsCompletedBaseline' => true,
            'dashPrefixEqualsCompletedBaseline' => true,
        ];
        $encodedReceipt = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        file_put_contents($this->directory . '/qualification.json', $encodedReceipt);
        if (is_dir('/tmp/baander-run')) {
            file_put_contents('/tmp/baander-run/progressive-media-publication.json', $encodedReceipt);
        }
    }

    private function startHttpServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = $this->start([PHP_BINARY, '-d', 'error_log=' . $this->directory . '/http-errors.log', '-S', $address,
            dirname(__DIR__, 4) . '/Fixtures/Transcode/ProgressiveMediaRouter.php'],
            ['BAANDER_PROGRESSIVE_DIRECTORY' => $this->directory], captureOutput: false);
        $server->setTimeout(null);
        $this->until(static function () use ($address): bool {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if ($connection === false) {
                return false;
            }
            fclose($connection);
            return true;
        }, 'Disposable HTTP service did not start.', $server);
        return 'http://' . $address;
    }

    private function manifestUrl(string $base, string $resource): string
    {
        $unsigned = '/api/transcode/' . str_repeat('V', 21) . '/' . $resource . '?'
            . http_build_query(['set' => str_repeat('J', 21) . '.1']);
        return (new HmacStreamAuthAdapter('disposable-baander-publication-fixture', $base))->signUrl($unsigned)['url'];
    }

    /** @return list<string> */
    private function consumer(string $url, string $name, ?int $duration = null): array
    {
        $prefix = $this->directory . '/' . $name;
        $inputOptions = str_starts_with($name, 'hls-') ? ['-live_start_index', '0'] : [];
        $limit = $duration === null ? [] : ['-t', (string) $duration];
        return ['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-xerror', '-y', '-threads', '1', '-filter_threads', '1',
            ...$inputOptions, '-headers', 'X-Baander-Test-Consumer: ' . $name . "\r\n", '-i', $url,
            ...$limit, '-map', '0:v:0', '-vf', 'scale=16:16,setpts=PTS-STARTPTS', '-c:v', 'rawvideo', '-threads:v', '1',
            '-pix_fmt', 'gray', '-fps_mode', 'passthrough', '-flush_packets', '1', '-f', 'rawvideo', $prefix . '.gray',
            ...$limit, '-map', '0:a:0', '-c:a', 'pcm_s16le', '-ac', '2', '-ar', '48000', '-flush_packets', '1', '-f', 's16le', $prefix . '.pcm',
            ...$limit, '-map', '0', '-c', 'copy', '-flush_packets', '1', '-f', 'framecrc', $prefix . '.framecrc'];
    }

    /** @return array<string, list<array{gray: int, pcm: int, framecrc: int, time: float, segments: int}>> */
    private function observeDynamicOutputs(Process $producer, PublishedPresentationReader $reader): array
    {
        $observed = ['hls' => [], 'dash' => []];
        while ($producer->isRunning()) {
            $this->superviseChildren();
            $presentation = $reader->read($this->directory);
            if ($presentation !== null && !$presentation->complete) {
                foreach (['hls' => 'hls-live', 'dash' => 'dash-live-prefix'] as $format => $name) {
                    $sizes = $this->outputSizes($name);
                    if ($sizes['gray'] > 0 && $sizes['pcm'] > 0) {
                        $last = array_key_last($observed[$format]);
                        $previous = $last === null ? null : $observed[$format][$last];
                        if ($previous === null || $previous['gray'] !== $sizes['gray'] || $previous['pcm'] !== $sizes['pcm']) {
                            $observed[$format][] = $sizes + ['time' => microtime(true),
                                'segments' => count($presentation->tracks['video']->segments)];
                        }
                    }
                }
            }
            usleep(20_000);
        }
        $this->assertSucceeded($producer);
        foreach ($observed as $format => $snapshots) {
            self::assertGreaterThan(1, count(array_unique(array_column($snapshots, 'gray'))), $format . ' must decode growing video during publication.');
            self::assertGreaterThan(1, count(array_unique(array_column($snapshots, 'pcm'))), $format . ' must decode growing audio during publication.');
        }
        return $observed;
    }

    /** @return array{gray: int, pcm: int, framecrc: int} */
    private function outputSizes(string $name): array
    {
        $result = [];
        foreach (['gray', 'pcm', 'framecrc'] as $extension) {
            $path = $this->directory . '/' . $name . '.' . $extension;
            clearstatcache(true, $path);
            $result[$extension] = is_file($path) ? (filesize($path) ?: 0) : 0;
        }
        return $result;
    }

    private function assertSameOutputs(string $live, string $completed): void
    {
        foreach (['gray', 'pcm', 'framecrc'] as $extension) {
            self::assertFileEquals($this->directory . '/' . $completed . '.' . $extension,
                $this->directory . '/' . $live . '.' . $extension);
        }
    }

    /** @return array{videoFrames: float|int, pcmBytes: int, audioSeconds: float|int} */
    private function assertDecodedDuration(string $name, int $duration, int $frames, int $frameTolerance): array
    {
        $sizes = $this->outputSizes($name);
        self::assertGreaterThan(0, $sizes['gray']);
        self::assertSame(0, $sizes['gray'] % 256);
        self::assertEqualsWithDelta($frames, $sizes['gray'] / 256, $frameTolerance);
        self::assertEqualsWithDelta($duration, $sizes['pcm'] / (48000 * 2 * 2), 0.1);
        return ['videoFrames' => $sizes['gray'] / 256, 'pcmBytes' => $sizes['pcm'],
            'audioSeconds' => $sizes['pcm'] / (48000 * 2 * 2)];
    }

    private function assertMonotonicPackets(string $path): void
    {
        $previous = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = array_map('trim', explode(',', $line));
            $stream = (int) $fields[0];
            $dts = (int) $fields[1];
            if (isset($previous[$stream])) {
                self::assertGreaterThan($previous[$stream], $dts);
            }
            $previous[$stream] = $dts;
        }
        self::assertCount(2, $previous);
    }

    /**
     * @param list<string> $arguments
     * @param array<string,string> $environment
     */
    private function start(array $arguments, array $environment = [], bool $captureOutput = true): Process
    {
        $process = new Process($arguments, env: $environment, timeout: 45);
        $this->children[] = $process;
        if (!$captureOutput) {
            $process->disableOutput();
        }
        $process->start();
        return $process;
    }

    /** @param list<string> $arguments */
    private function runMedia(array $arguments): void
    {
        $this->waitForExit($this->start($arguments));
    }

    private function waitForExit(Process $process): void
    {
        while ($process->isRunning()) {
            $this->superviseChildren();
            usleep(20_000);
        }
        $this->assertSucceeded($process);
    }

    private function superviseChildren(): void
    {
        foreach ($this->children as $child) {
            if (!$child->isOutputDisabled()) {
                $child->getOutput();
                $child->getErrorOutput();
            }
            try {
                $child->checkTimeout();
            } catch (ProcessTimedOutException $timeout) {
                $this->assertSucceeded($child);
                throw $timeout;
            }
        }
    }

    private function assertSucceeded(Process $process): void
    {
        self::assertTrue($process->isSuccessful(), $process->isSuccessful() ? '' : $this->diagnostics($process));
        self::assertSame('', $process->getErrorOutput());
    }

    private function until(callable $ready, string $failure, Process $process): void
    {
        $deadline = microtime(true) + 25;
        while (!$ready()) {
            $this->superviseChildren();
            self::assertTrue($process->isRunning(), $failure . "\n" . ($process->isOutputDisabled() ? '' : $process->getErrorOutput()));
            if (microtime(true) >= $deadline) {
                self::fail($failure . "\n" . $this->diagnostics($process));
            }
            usleep(20_000);
        }
    }

    private function diagnostics(Process $process): string
    {
        $message = $process->getCommandLine() . "\n" . ($process->isOutputDisabled() ? '' : $process->getErrorOutput());
        foreach (['requests.jsonl', 'served.mpd', 'served-video.m3u8', 'served-audio.m3u8', 'http-errors.log'] as $file) {
            if (is_file($this->directory . '/' . $file)) {
                $message .= "\n" . $file . ":\n" . file_get_contents($this->directory . '/' . $file, length: 32768);
            }
        }
        foreach ($this->children as $child) {
            if (!$child->isOutputDisabled()) {
                $message .= "\nChild stderr:\n" . substr($child->getErrorOutput(), -8192);
            }
        }
        return $message;
    }

    private function preserveArtifacts(string $phase): void
    {
        if (!is_dir('/tmp/baander-run')) {
            return;
        }
        $target = '/tmp/baander-run/progressive-media-' . $phase;
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (!is_file($path) || is_link($path) || str_ends_with($path, '.tmp')) {
                continue;
            }
            $source = fopen($path, 'rb');
            $destination = fopen($target . '/' . basename($path), 'wb');
            if ($source === false || $destination === false) {
                throw new \RuntimeException('Unable to preserve the native publication evidence.');
            }
            $stat = fstat($source);
            if ($stat === false || stream_copy_to_stream($source, $destination, $stat['size']) === false) {
                throw new \RuntimeException('Unable to snapshot the native publication evidence.');
            }
            fclose($source);
            fclose($destination);
        }
        $processes = [];
        foreach ($this->children as $index => $child) {
            $processes[] = ['command' => $child->getCommandLine(), 'exitCode' => $child->getExitCode()];
            if (!$child->isOutputDisabled()) {
                file_put_contents($target . '/child-' . $index . '.stderr.log', $child->getErrorOutput());
            }
        }
        file_put_contents($target . '/children.json', json_encode($processes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
