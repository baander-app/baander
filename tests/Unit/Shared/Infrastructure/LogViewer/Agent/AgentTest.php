<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer\Agent;

use App\Shared\Infrastructure\LogViewer\Agent\AgentConfiguration;
use App\Shared\Infrastructure\LogViewer\Agent\DockerClient;
use App\Shared\Infrastructure\LogViewer\Agent\DockerFrameDecoder;
use App\Shared\Infrastructure\LogViewer\Agent\DockerRecords;
use App\Shared\Infrastructure\LogViewer\LogNormalizer;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testFragmentedFramesPreserveStreamAndContent(): void
    {
        $decoder = new DockerFrameDecoder();
        $bytes = "\x01\0\0\0".pack('N', 4)."one\n"."\x02\0\0\0".pack('N', 4)."two\n";
        $frames = [];
        foreach (str_split($bytes, 3) as $fragment) {
            array_push($frames, ...$decoder->push($fragment));
        }
        $decoder->finish();
        self::assertSame([['stream' => 'stdout', 'data' => "one\n"], ['stream' => 'stderr', 'data' => "two\n"]], $frames);
    }

    public function testOversizedFrameAndLineDrainWithExplicitTruncation(): void
    {
        $payload = '2026-11-20T12:00:00Z '.str_repeat('x', 2097152)."\n2026-11-20T12:00:01Z next record\n";
        $decoder = new DockerFrameDecoder();
        $records = new DockerRecords();
        $decoded = [];
        $bytes = "\x01\0\0\0".pack('N', strlen($payload)).$payload;
        foreach (str_split($bytes, 65536) as $chunk) {
            $frames = $decoder->push($chunk);
            foreach ($frames as $frame) {
                self::assertLessThanOrEqual(65536, strlen($frame['data']));
            }
            array_push($decoded, ...$records->feed($frames));
        }
        $decoder->finish();
        array_push($decoded, ...$records->finish());
        self::assertCount(2, $decoded);
        self::assertLessThanOrEqual(262145, strlen($decoded[0]['raw']));
        self::assertTrue((new LogNormalizer())->normalize($decoded[0]['raw'])['truncated']);
        self::assertSame('next record', $decoded[1]['raw']);
    }

    public function testTruncatedFrameIsExplicit(): void
    {
        $decoder = new DockerFrameDecoder();
        $decoder->push("\x01\0");
        $this->expectException(\RuntimeException::class);
        $decoder->finish();
    }

    public function testAdmissionRequiresExactOwnerAndServiceAndExcludesCollector(): void
    {
        $config = new AgentConfiguration(['nodeId' => 'local', 'databasePath' => '/tmp/index', 'tokenFile' => '/tmp/token', 'checkpointTokenFile' => '/tmp/checkpoint', 'dockerSocket' => '/var/run/docker.sock', 'expectedDaemonId' => 'daemon', 'containerGroups' => [['labels' => ['app.baander.worker.namespace' => 'preview'], 'serviceLabel' => 'role', 'services' => ['execution']]]]);
        $container = ['Labels' => ['app.baander.worker.namespace' => 'preview', 'role' => 'execution']];
        self::assertTrue($config->admits($container));
        $container['Labels']['app.baander.worker.namespace'] = 'other';
        self::assertFalse($config->admits($container));
        $container['Labels']['app.baander.worker.namespace'] = 'preview';
        $container['Labels']['io.baander.log-agent'] = 'true';
        self::assertFalse($config->admits($container));
    }
    public function testFragmentedWritesAndMultilineExceptionsRemainOneRecord(): void
    {
        $records = DockerRecords::decode([
            ['stream' => 'stderr', 'data' => '2026-11-20T12:00:00Z exception: par'],
            ['stream' => 'stderr', 'data' => "tial\n2026-11-20T12:00:00Z   stack frame\n"],
        ]);
        self::assertCount(1, $records);
        self::assertSame("exception: partial\n  stack frame", $records[0]['raw']);
        self::assertSame('stderr', $records[0]['stream']);
    }

    public function testConfigurationLoadsInlineJsonAndStandaloneFile(): void
    {
        $json = json_encode(['nodeId' => 'local', 'databasePath' => '/tmp/logs.sqlite', 'tokenFile' => '/tmp/query-token', 'checkpointTokenFile' => '/tmp/checkpoint-token'], JSON_THROW_ON_ERROR);
        self::assertSame('local', AgentConfiguration::load('  '.$json)->get('nodeId'));
        $path = tempnam(sys_get_temp_dir(), 'baander-agent-config-');
        try {
            file_put_contents($path, $json);
            self::assertSame('local', AgentConfiguration::load($path)->get('nodeId'));
        } finally {
            unlink($path);
        }
    }

    public function testInvalidFrameStreamIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DockerFrameDecoder())->push("\x03\0\0\0".pack('N', 1).'x');
    }

    public function testDockerTimestampCursorUsesNumericSecondsAndNanoseconds(): void
    {
        self::assertSame('1795176000.123456789', DockerClient::timestampCursor('2026-11-20T12:00:00.123456789Z'));
        self::assertSame('1795176000.100000000', DockerClient::timestampCursor('2026-11-20T13:00:00.1+01:00'));
    }

}
