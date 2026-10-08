<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Swoole\AudioRenditionPoolWorker;
use App\Transcode\Infrastructure\Transcode\AudioRenditionCache;
use Closure;
use Generator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Swoole\Table;
use Symfony\Component\Process\Process;

/**
 * The cached audio rendition behind the transcoding track stream: one encode
 * per rendition, progressive reads while it runs, and the cache hand-off.
 *
 * A stand-in encoder script replaces FFmpeg so each run is observable; the
 * functional test exercises the real encoder.
 */
final class AudioRenditionCacheTest extends TestCase
{
    private const string TRACK = 'trackPublicId00000001';

    private string $root;
    private string $source;
    private string $encoder;
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logs = [];
    /** @var list<array{payload: string, key: string}> */
    private array $dispatched = [];
    /** @var array<string, array{data: string, status: string}> */
    private array $results = [];
    private ?Closure $onDispatch = null;
    /** Whether the stand-in pool refuses limited jobs, as when the encode limit is reached. */
    private bool $poolAtLimit = false;
    /** @var list<array{key: string, limit: int}> */
    private array $limits = [];
    /** A stand-in for an encoder in another process; stopped after each test. */
    private ?Process $otherEncoder = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-audio-rendition-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/cache', 0700, true));
        $this->source = $this->root . '/track.flac';
        self::assertNotFalse(file_put_contents($this->source, 'original audio'));
        $this->encoder = $this->root . '/encoder';
    }

    protected function tearDown(): void
    {
        $this->otherEncoder?->stop(0);
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testFirstRequestStreamsOneEncodeAndLaterRequestsUseTheCachedRendition(): void
    {
        $this->writeEncoder("printf '%s ' \"\$@\" > \"\$out\"");
        $this->onDispatch = $this->runWorker(...);
        $cache = $this->cache();

        $first = $cache->open(self::TRACK, $this->source, AudioRenditionFormat::Mp3, 192_000);

        self::assertFalse($first->isComplete());
        self::assertSame(AudioRenditionFormat::Mp3, $first->format);
        $body = implode('', iterator_to_array($first->chunks(), false));
        self::assertStringContainsString('-c:a libmp3lame', $body);
        self::assertStringContainsString('-b:a 192000', $body);
        self::assertStringContainsString('-f mp3', $body);

        $cached = $cache->open(self::TRACK, $this->source, AudioRenditionFormat::Mp3, 192_000);

        self::assertTrue($cached->isComplete());
        self::assertSame($body, file_get_contents((string) $cached->path));
        self::assertCount(1, $this->dispatched);
        self::assertSame(1, $this->encoderRuns());
        self::assertSame([], glob($this->root . '/cache/audio-renditions/' . self::TRACK . '/*.part'));
        self::assertSame([], $this->results, 'the request collected its pool result');
    }

    public function testSimultaneousFirstRequestsShareOneRunningEncode(): void
    {
        $lock = null;
        $this->onDispatch = function (string $payload) use (&$lock): void {
            // The pool worker has taken the rendition lock and written the first bytes.
            $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $lock = fopen($job['lock_path'], 'c');
            self::assertTrue(flock($lock, LOCK_EX));
            file_put_contents($job['output_path'] . '.part', 'first-');
        };
        $cache = $this->cache();

        $starter = $cache->open(self::TRACK, $this->source, AudioRenditionFormat::Aac, 160_000);
        $joiner = $cache->open(self::TRACK, $this->source, AudioRenditionFormat::Aac, 160_000);

        self::assertCount(1, $this->dispatched, 'the second request joined instead of starting an encode');
        self::assertFalse($starter->isComplete());
        self::assertFalse($joiner->isComplete());
        $starterChunks = $this->generator($starter->chunks());
        $joinerChunks = $this->generator($joiner->chunks());
        self::assertSame('first-', $starterChunks->current());
        self::assertSame('first-', $joinerChunks->current());

        // The encoder writes the rest, publishes the rendition and releases the lock.
        $job = json_decode($this->dispatched[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($job['output_path'] . '.part', 'second', FILE_APPEND);
        self::assertTrue(rename($job['output_path'] . '.part', $job['output_path']));
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->results[$this->dispatched[0]['key']] = ['status' => 'ok', 'data' => '{"status":"complete"}'];

        self::assertSame('first-second', 'first-' . $this->rest($starterChunks));
        self::assertSame('first-second', 'first-' . $this->rest($joinerChunks));
        self::assertSame([], $this->results);
        self::assertSame([], $this->logs);
        self::assertTrue($cache->open(self::TRACK, $this->source, AudioRenditionFormat::Aac, 160_000)->isComplete());
    }

    public function testFailedEncodeIsReportedAndLoggedAndLeavesNoPartialRendition(): void
    {
        $this->writeEncoder("printf 'partial' > \"\$out\"\necho 'Invalid data found when processing input' >&2\nexit 1");
        $this->onDispatch = $this->runWorker(...);

        try {
            $this->cache()->open(self::TRACK, $this->source, AudioRenditionFormat::Opus, 96_000);
            self::fail('A failed encode must not produce a rendition.');
        } catch (AudioRenditionFailedException) {
        }

        $files = glob($this->root . '/cache/audio-renditions/' . self::TRACK . '/*') ?: [];
        self::assertSame([], array_values(array_filter($files, static fn (string $file): bool => !str_ends_with($file, '.lock'))));
        self::assertCount(1, $this->logs);
        self::assertSame('error', $this->logs[0]['level']);
        self::assertSame(self::TRACK, $this->logs[0]['context']['track']);
        self::assertSame('opus', $this->logs[0]['context']['format']);
        self::assertStringContainsString('Invalid data found', (string) $this->logs[0]['context']['reason']);
    }

    public function testANewEncodeIsRefusedWithoutDispatchWhenTheEncodeLimitIsReached(): void
    {
        $this->poolAtLimit = true;

        try {
            $this->cache()->open(self::TRACK, $this->source, AudioRenditionFormat::Mp3, 192_000);
            self::fail('An encode beyond the limit must not start.');
        } catch (AudioRenditionBusyException) {
        }

        self::assertSame([], $this->dispatched);
        self::assertSame([['key' => AudioRenditionPoolWorker::JOB_TYPE, 'limit' => 3]], $this->limits);
        self::assertSame([], $this->logs);
    }

    public function testAListenerStillJoinsARunningEncodeWhenTheEncodeLimitIsReached(): void
    {
        $output = $this->renditionPath(AudioRenditionFormat::Aac, 160);
        self::assertTrue(mkdir(dirname($output), 0700, true));
        $lock = fopen(substr($output, 0, -strlen('.aac')) . '.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        file_put_contents($output . '.part', 'running-');
        $this->poolAtLimit = true;

        $joiner = $this->cache()->open(self::TRACK, $this->source, AudioRenditionFormat::Aac, 160_000);

        self::assertSame([], $this->limits, 'joining a running encode does not count against the limit');
        self::assertSame('running-', $this->generator($joiner->chunks())->current());
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testAStalePartialFromADeadEncoderIsRemovedBeforeTheNewEncodeStarts(): void
    {
        $output = $this->renditionPath(AudioRenditionFormat::Mp3, 192);
        self::assertTrue(mkdir(dirname($output), 0700, true));
        file_put_contents($output . '.part', 'stale bytes of a dead encoder');
        $this->writeEncoder("printf 'fresh' > \"\$out\"");
        $partialAtDispatch = null;
        $this->onDispatch = function (string $payload, string $key) use ($output, &$partialAtDispatch): void {
            // A queued pool job has not started yet, so listeners must not see the stale bytes.
            clearstatcache(true, $output . '.part');
            $partialAtDispatch = is_file($output . '.part');
            $this->runWorker($payload, $key);
        };

        $rendition = $this->cache()->open(self::TRACK, $this->source, AudioRenditionFormat::Mp3, 192_000);

        self::assertFalse($partialAtDispatch);
        self::assertSame('fresh', implode('', iterator_to_array($rendition->chunks(), false)));
    }

    public function testThePoolWorkerReturnsAtOnceWhenAnotherEncodeHoldsTheRenditionLock(): void
    {
        $this->writeEncoder("printf 'duplicate' > \"\$out\"");
        $output = $this->renditionPath(AudioRenditionFormat::Opus, 96);
        self::assertTrue(mkdir(dirname($output), 0700, true));
        $lockPath = substr($output, 0, -strlen('.opus')) . '.lock';
        $this->startOtherEncoder($lockPath, $output, holdSeconds: 5.0);

        $started = microtime(true);
        $result = (new AudioRenditionPoolWorker())->handle(json_encode([
            'type' => AudioRenditionPoolWorker::JOB_TYPE,
            'ffmpeg_path' => $this->encoder,
            'source_path' => $this->source,
            'output_path' => $output,
            'lock_path' => $lockPath,
            'format' => 'opus',
            'bitrate' => 96_000,
        ], JSON_THROW_ON_ERROR));

        self::assertLessThan(1.0, microtime(true) - $started, 'a duplicate job must not wait for the running encode');
        self::assertSame('running', json_decode($result, true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertSame(0, $this->encoderRuns());
    }

    public function testARequestWhoseJobFoundAnEncodeRunningStreamsThatEncode(): void
    {
        $this->onDispatch = function (string $payload, string $key): void {
            // Another request's encode took the lock first, so this job returned at once.
            $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $this->startOtherEncoder($job['lock_path'], $job['output_path'], holdSeconds: 0.0);
            $this->results[$key] = ['status' => 'ok', 'data' => '{"status":"running"}'];
        };

        $rendition = $this->cache()->open(self::TRACK, $this->source, AudioRenditionFormat::Aac, 128_000);

        self::assertFalse($rendition->isComplete());
        self::assertSame('first-second', implode('', iterator_to_array($rendition->chunks(), false)));
        self::assertCount(1, $this->dispatched);
        self::assertSame([], $this->results);
        self::assertSame([], $this->logs);
    }

    private function renditionPath(AudioRenditionFormat $format, int $kilobits): string
    {
        clearstatcache(true, $this->source);
        $fingerprint = hash('xxh64', sprintf('%d:%d', filemtime($this->source), filesize($this->source)));

        return (new SegmentFileResolver($this->root . '/cache'))->resolveAudioRenditionPath(
            self::TRACK,
            sprintf('%s-%dk-%s.%s', $format->value, $kilobits, $fingerprint, $format->extension()),
        );
    }

    /**
     * Start an encoder in another process: it takes the rendition lock, writes
     * `first-` then `second` to the partial file and publishes the rendition,
     * then keeps the lock for `$holdSeconds` more, standing in for a long encode.
     */
    private function startOtherEncoder(string $lockPath, string $output, float $holdSeconds): void
    {
        $script = <<<'PHP'
            [, $lockPath, $output, $hold] = $argv;
            $lock = fopen($lockPath, 'c');
            flock($lock, LOCK_EX);
            echo "locked\n";
            usleep(100_000);
            file_put_contents($output . '.part', 'first-');
            usleep(100_000);
            file_put_contents($output . '.part', 'second', FILE_APPEND);
            rename($output . '.part', $output);
            usleep((int) ((float) $hold * 1_000_000));
            PHP;
        $this->otherEncoder = new Process([PHP_BINARY, '-r', $script, $lockPath, $output, (string) $holdSeconds]);
        $this->otherEncoder->setTimeout(10);
        $this->otherEncoder->start();
        $this->otherEncoder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked'));
    }

    private function cache(): AudioRenditionCache
    {
        $test = $this;
        $pool = new class ($test) implements CpuProcessPoolInterface {
            public function __construct(private readonly AudioRenditionCacheTest $test)
            {
            }

            public function isRunning(): bool
            {
                return true;
            }

            public function dispatch(string $payload, string $resultKey): void
            {
                throw new \LogicException('Rendition encodes must be dispatched within the encode limit.');
            }

            public function dispatchWithinLimit(string $payload, string $resultKey, string $limitKey, int $limit): bool
            {
                return $this->test->recordLimitedDispatch($payload, $resultKey, $limitKey, $limit);
            }

            public function getResultTable(): ?Table
            {
                return null;
            }

            public function readResult(string $key): ?array
            {
                return $this->test->takeResult($key);
            }
        };
        $logger = new class ($test) extends AbstractLogger {
            public function __construct(private readonly AudioRenditionCacheTest $test)
            {
            }

            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->test->recordLog($level, (string) $message, $context);
            }
        };

        return new AudioRenditionCache(
            new SegmentFileResolver($this->root . '/cache'),
            $pool,
            new AudioRenditionPoolWorker(),
            $logger,
            $this->encoder,
            maxConcurrentEncodes: 3,
            pollIntervalSeconds: 0.001,
            startTimeoutSeconds: 2.0,
        );
    }

    /** @internal called by the stand-in pool */
    public function recordLimitedDispatch(string $payload, string $key, string $limitKey, int $limit): bool
    {
        $this->limits[] = ['key' => $limitKey, 'limit' => $limit];
        if ($this->poolAtLimit) {
            return false;
        }
        $this->recordDispatch($payload, $key);

        return true;
    }

    /**
     * @internal called by the stand-in logger
     * @param array<mixed> $context
     */
    public function recordLog(mixed $level, string $message, array $context): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @internal called by the stand-in pool */
    public function recordDispatch(string $payload, string $key): void
    {
        $this->dispatched[] = ['payload' => $payload, 'key' => $key];
        if ($this->onDispatch !== null) {
            ($this->onDispatch)($payload, $key);
        }
    }

    /**
     * @internal called by the stand-in pool
     * @return array{data: string, status: string}|null
     */
    public function takeResult(string $key): ?array
    {
        $result = $this->results[$key] ?? null;
        unset($this->results[$key]);

        return $result;
    }

    private function runWorker(string $payload, string $key): void
    {
        try {
            $this->results[$key] = ['status' => 'ok', 'data' => (new AudioRenditionPoolWorker())->handle($payload)];
        } catch (\Throwable $error) {
            $this->results[$key] = ['status' => 'error', 'data' => $error->getMessage()];
        }
    }

    private function writeEncoder(string $body): void
    {
        $script = "#!/bin/sh\nfor out; do :; done\necho run >> " . escapeshellarg($this->root . '/runs') . "\n" . $body . "\n";
        self::assertNotFalse(file_put_contents($this->encoder, $script));
        self::assertTrue(chmod($this->encoder, 0700));
    }

    private function encoderRuns(): int
    {
        return is_file($this->root . '/runs') ? count(file($this->root . '/runs') ?: []) : 0;
    }

    /**
     * @param iterable<string> $chunks
     * @return Generator<int, string>
     */
    private function generator(iterable $chunks): Generator
    {
        self::assertInstanceOf(Generator::class, $chunks);

        return $chunks;
    }

    /** @param Generator<int, string> $chunks */
    private function rest(Generator $chunks): string
    {
        $rest = '';
        for ($chunks->next(); $chunks->valid(); $chunks->next()) {
            $rest .= $chunks->current();
        }

        return $rest;
    }
}
