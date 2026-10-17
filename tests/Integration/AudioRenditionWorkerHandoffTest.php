<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Transcode\AudioRenditionCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

final class AudioRenditionWorkerHandoffTest extends TestCase
{
    use MediaRuntimePersistenceHarness {
        setUp as private prepareDatabase;
        tearDown as private cleanDatabase;
    }

    private string $root;
    private string $source;
    private string $encoder;
    private ?Process $workerProcess = null;

    protected function setUp(): void
    {
        $this->prepareDatabase();
        $this->root = sys_get_temp_dir() . '/audio-handoff-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->source = $this->root . '/source.flac';
        $this->encoder = $this->root . '/encoder';
        file_put_contents($this->source, 'admitted source');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->workerProcess?->stop(.2);
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
        $this->cleanDatabase();
    }

    public function testIndependentWorkerDiscoversCommittedAdmissionWithoutAWakeupAndPublishesGrowingBytes(): void
    {
        $this->startWorker(.3);
        $cache = $this->cache();
        $rendition = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertFalse($rendition->isComplete());
        self::assertSame('first-second', implode('', iterator_to_array($rendition->chunks(), false)));
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        $cached = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertTrue($cached->isComplete());
        self::assertSame('first-second', file_get_contents($cached->path));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        self::assertSame('complete', $this->first->fetchOne('SELECT state FROM audio_rendition_runtime'));
        self::assertSame(1, (int) file_get_contents($this->root . '/runs'));
    }

    public function testPinnedReaderRejectsLeaseLossAndExpiredWorkerCannotPublishACompletedFile(): void
    {
        $this->startWorker(2);
        $rendition = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        $chunks = $rendition->chunks();
        self::assertInstanceOf(\Generator::class, $chunks);
        self::assertSame('first-', $chunks->current());
        $final = (string) $this->first->fetchOne('SELECT final_path FROM audio_rendition_runtime');
        $this->first->executeStatement("UPDATE audio_rendition_runtime SET lease_expires_at = clock_timestamp() - interval '1 second'");
        try {
            $chunks->next();
            self::fail('Reader must reject lost ownership.');
        } catch (AudioRenditionFailedException $error) {
            self::assertStringContainsString('lost ownership', $error->getMessage());
        }
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        self::assertFileDoesNotExist($final);
        self::assertFileDoesNotExist($final . '.part');
        self::assertCount(1, (new AudioRenditionRuntime($this->second))->pending());
        $this->startWorker(.1);
        $deadline = microtime(true) + 2;
        while ((int) $this->first->fetchOne('SELECT generation FROM audio_rendition_runtime') < 2 && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $recovered = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertSame('first-second', implode('', iterator_to_array($recovered->chunks(), false)));
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        self::assertSame(2, (int) $this->first->fetchOne('SELECT generation FROM audio_rendition_runtime'));
    }

    private function cache(): AudioRenditionCache
    {
        return new AudioRenditionCache(new SegmentFileResolver($this->root . '/cache'), new AudioRenditionRuntime($this->first), new NullLogger(), pollIntervalSeconds: .005, startTimeoutSeconds: 3);
    }

    private function startWorker(float $delay): void
    {
        $script = "#!" . PHP_BINARY . "\n<?php\n";
        $script .= '$out = $argv[count($argv) - 1];' . "\n";
        $script .= 'file_put_contents(' . var_export($this->root . '/runs', true) . ', "1");' . "\n";
        $script .= 'file_put_contents($out, "first-"); usleep(' . (int) ($delay * 1_000_000) . '); file_put_contents($out, "second", FILE_APPEND);' . "\n";
        file_put_contents($this->encoder, $script);
        chmod($this->encoder, 0700);
        $this->workerProcess = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Media/audio-rendition-worker.php', $this->schema, $this->encoder]);
        $this->workerProcess->setTimeout(8);
        $this->workerProcess->start();
    }
}
