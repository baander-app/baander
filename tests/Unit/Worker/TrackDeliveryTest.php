<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Tests\Fixtures\TwoCommand\TrackDelivery;
use App\Tests\Fixtures\WebRuntime\HttpExchange;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class TrackDeliveryTest extends TestCase
{
    private string $directory;
    /** @var array<string,string> */
    private array $bases = [];
    /** @var list<Process> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-track-delivery-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'testsrc2=size=32x32:rate=10:duration=0.4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=0.4',
            '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '1',
            '-c:a', 'aac', '-f', 'dash', '-seg_duration', '1',
            '-init_seg_name', 'init-$RepresentationID$.mp4',
            '-media_seg_name', 'track-$RepresentationID$-seg-$Number%05d$.m4s',
            $this->directory . '/manifest.mpd',
        ], timeout: 10)->mustRun();
        file_put_contents($this->directory . '/mode', 'normal');
        file_put_contents($this->directory . '/router.php', <<<'PHP'
<?php
$mode = file_get_contents(__DIR__ . '/mode');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/ready') { echo 'ready'; return; }
if (!preg_match('~^/tracks/(video|audio)/(init\.mp4|segment\.m4s)$~D', $path, $match)) { http_response_code(404); return; }
$kind = $match[1];
$part = $match[2];
$tampered = ($_GET['kind'] ?? '') !== $kind || ($_GET['index'] ?? '') !== '0' || ($_GET['generation'] ?? '') !== '3';
if ($mode === 'slow-verification' && $tampered) { usleep(200000); }
if ($mode === 'slow-fragment' && !$tampered && $kind === 'audio' && $part === 'segment.m4s') { usleep(700000); }
if ($mode === 'slow-pair' && !$tampered && $part === 'segment.m4s') { usleep(700000); }
file_put_contents(__DIR__ . '/requests.jsonl', json_encode(['kind' => $kind, 'part' => $part, 'tampered' => $tampered, 'completedAt' => hrtime(true) / 1e9]) . "\n", FILE_APPEND);
if ($tampered) { http_response_code($mode === 'accepted-tampering' ? 200 : 403); echo 'tampering'; return; }
if ($mode === 'corrupt-media' && $kind === 'video') { echo 'invalid media'; return; }
$id = $kind === 'video' ? '0' : '1';
if ($mode === 'slow-body' && $part === 'segment.m4s') {
    $contents = file_get_contents(__DIR__ . '/track-' . $id . '-seg-00001.m4s');
    header('Content-Length: ' . strlen($contents));
    echo substr($contents, 0, 32); flush(); usleep(100000); echo substr($contents, 32); return;
}
readfile(__DIR__ . '/' . ($part === 'init.mp4' ? 'init-' . $id . '.mp4' : 'track-' . $id . '-seg-00001.m4s'));
PHP);
        foreach (['video', 'audio'] as $kind) {
            $this->bases[$kind] = $this->startServer();
        }
    }

    private function startServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://' . $address;
        $server = new Process([PHP_BINARY, '-d', 'output_buffering=0', '-d', 'implicit_flush=1', '-S', $address, $this->directory . '/router.php'], timeout: 15);
        $server->start();
        $this->servers[] = $server;
        $deadline = microtime(true) + 2;
        do {
            $ready = @file_get_contents($base . '/ready');
            if ($ready === 'ready') {
                return $base;
            }
            usleep(10000);
        } while ($server->isRunning() && microtime(true) < $deadline);
        self::fail('Local media server did not start: ' . $server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }
        new Filesystem()->remove($this->directory);
    }

    public function testSlowCertificationDoesNotChangePairedDeliveryAvailability(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-verification');
        $started = hrtime(true) / 1e9;
        $delivery = TrackDelivery::inspect($this->resources(), $this->directory . '/delivered');
        $finished = hrtime(true) / 1e9;
        self::assertLessThan(.5, $delivery['availableAt'] - $started);
        self::assertGreaterThan(1.0, $finished - $started);
        self::assertSame(['video', 'audio'], array_keys($delivery['tracks']));
        self::assertSame('h264', $delivery['tracks']['video']['codec']);
        self::assertSame('aac', $delivery['tracks']['audio']['codec']);
        foreach ($delivery['tracks'] as $track) {
            self::assertSame(['track' => 403, 'index' => 403, 'generation' => 403], $track['tamperedStatuses']);
        }
        $requests = $this->requests();
        self::assertCount(10, $requests);
        self::assertSame([false, false, false, false], array_column(array_slice($requests, 0, 4), 'tampered'));
        self::assertGreaterThanOrEqual($requests[3]['completedAt'], $delivery['availableAt']);
        self::assertLessThan($requests[4]['completedAt'], $delivery['availableAt']);
    }

    public function testIndependentFragmentsTransferConcurrently(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-pair');
        $started = hrtime(true) / 1e9;
        $delivery = TrackDelivery::inspect($this->resources(), $this->directory . '/delivered');
        self::assertLessThan(1.2, $delivery['availableAt'] - $started, 'Independent 0.7-second responses must overlap instead of adding to 1.4 seconds.');
        self::assertGreaterThan(.6, $delivery['availableAt'] - $started);
        self::assertCount(2, $delivery['tracks']);
    }

    public function testSlowRealFragmentRemainsInsideTheMeasuredInterval(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-fragment');
        $started = hrtime(true) / 1e9;
        $delivery = TrackDelivery::inspect($this->resources(), $this->directory . '/delivered');
        self::assertGreaterThan(.5, $delivery['availableAt'] - $started);
        $requests = $this->requests();
        $audio = array_values(array_filter($requests, static fn(array $request): bool => $request['kind'] === 'audio' && $request['part'] === 'segment.m4s' && !$request['tampered']));
        self::assertCount(1, $audio);
        self::assertGreaterThanOrEqual($audio[0]['completedAt'], $delivery['availableAt']);
    }

    public function testAvailableButCorruptMediaStillFailsCertification(): void
    {
        file_put_contents($this->directory . '/mode', 'corrupt-media');
        $this->expectException(ProcessFailedException::class);
        TrackDelivery::inspect($this->resources(), $this->directory . '/delivered');
    }

    public function testBatchTimeoutPreservesCompletedResponseAndAbortsPendingExchange(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-fragment');
        $resources = $this->resources();
        $fast = new HttpExchange($resources['video']['initUrl'], []);
        $slow = new HttpExchange($resources['audio']['segmentUrl'], []);
        $started = hrtime(true) / 1e9;
        try {
            HttpExchange::completeAll(['fast' => $fast, 'slow' => $slow], .1);
            self::fail('A pending HTTP request escaped the batch deadline.');
        } catch (RuntimeException $error) {
            self::assertSame('The HTTP batch did not finish in time.', $error->getMessage());
        }
        self::assertLessThan(.4, hrtime(true) / 1e9 - $started);
        self::assertTrue($fast->done);
        self::assertSame(200, $fast->status());
        self::assertSame(file_get_contents($this->directory . '/init-0.mp4'), $fast->body);
        self::assertTrue($slow->done);
        self::assertSame(CURLE_ABORTED_BY_CALLBACK, $slow->result);
        try {
            $slow->complete();
            self::fail('An aborted exchange became a successful response.');
        } catch (RuntimeException $repeated) {
            self::assertSame($error, $repeated);
        }
    }

    public function testBatchReturnsSuccessfulAndRejectedHttpResponsesWithTheirKeys(): void
    {
        $resources = $this->resources();
        $good = new HttpExchange($resources['video']['initUrl'], []);
        $refused = new HttpExchange($resources['audio']['segmentUrl'] . '&index=999999', []);
        self::assertSame(['good' => $good, 'refused' => $refused], HttpExchange::completeAll(['good' => $good, 'refused' => $refused]));
        self::assertSame(200, $good->status());
        self::assertSame(403, $refused->status());
        self::assertTrue($good->done && $refused->done);
    }

    public function testBatchTransportFailureAbortsItsOtherPendingExchange(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-fragment');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $broken = new HttpExchange('http://' . $address . '/', []);
        $slow = new HttpExchange($this->resources()['audio']['segmentUrl'], []);
        try {
            HttpExchange::completeAll([$slow, $broken], 1);
            self::fail('A failed transport became a successful batch.');
        } catch (RuntimeException $error) {
            self::assertStringStartsWith('Request failed:', $error->getMessage());
        }
        self::assertTrue($slow->done);
        self::assertSame(CURLE_ABORTED_BY_CALLBACK, $slow->result);
        self::assertSame(CURLE_COULDNT_CONNECT, $broken->result);
    }

    public function testStreamingUntilStillObservesPartialBytesBeforeCompletion(): void
    {
        file_put_contents($this->directory . '/mode', 'slow-body');
        $exchange = new HttpExchange($this->resources()['video']['segmentUrl'], []);
        self::assertTrue($exchange->until(static fn(HttpExchange $value): bool => strlen($value->body) >= 32, 1));
        self::assertFalse($exchange->done);
        $exchange->complete(1);
        self::assertTrue($exchange->done);
        self::assertSame(file_get_contents($this->directory . '/track-0-seg-00001.m4s'), $exchange->body);
    }

    public function testAcceptedTamperedSignatureStillFailsCertification(): void
    {
        file_put_contents($this->directory . '/mode', 'accepted-tampering');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Altered track signature was accepted.');
        TrackDelivery::inspect($this->resources(), $this->directory . '/delivered');
    }

    /** @return array<string,array{initUrl:string,segmentUrl:string}> */
    private function resources(): array
    {
        $resources = [];
        foreach (['video', 'audio'] as $kind) {
            $query = '?kind=' . $kind . '&index=0&generation=3';
            $resources[$kind] = ['initUrl' => $this->bases[$kind] . '/tracks/' . $kind . '/init.mp4' . $query,
                'segmentUrl' => $this->bases[$kind] . '/tracks/' . $kind . '/segment.m4s' . $query];
        }
        return $resources;
    }

    /** @return list<array<string,mixed>> */
    private function requests(): array
    {
        return array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }
}
