<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class VideoHelperPayloadTest extends TestCase
{
    public function testGenerationSpecificProbesAndSubtitlesSubmitRemoteArgv(): void
    {
        $commands = [];
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$commands): NativeExecutionHandle {
            $commands[] = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($commands));
        });
        $facade = new TranscodeProcessPool($client, new NullLogger(), new JsonEncoder());
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/cache', Uuid::generate());
        $first = $facade->probeVideo($job, '/media/source with spaces.mkv', 1);
        $replacement = $facade->probeVideo($job, '/media/source with spaces.mkv', 2);
        $directory = sys_get_temp_dir() . '/baander-remote-subtitle-' . bin2hex(random_bytes(6));
        try {
            $subtitle = $facade->extractSubtitles($job, '/media/source with spaces.mkv', 'en', $directory . '/full.vtt', 2);
            self::assertNotSame($first, $replacement);
            self::assertStringEndsWith(':2', $replacement);
            self::assertStringEndsWith(':2:en', $subtitle);
            self::assertSame('/usr/bin/ffprobe', $commands[1][0]);
            self::assertSame('/media/source with spaces.mkv', $commands[1][array_key_last($commands[1])]);
            self::assertSame('0:s:m:language:en', $commands[2][array_search('-map', $commands[2], true) + 1]);
            self::assertSame($directory . '/full.vtt', $commands[2][array_key_last($commands[2])]);
        } finally {
            rmdir($directory);
        }
    }

    public function testUnknownRemoteProbeCannotBecomeAResult(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturn(new NativeExecutionHandle(str_repeat('b', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'unknown']);
        $client->expects(self::never())->method('release');
        $facade = new TranscodeProcessPool($client, new NullLogger(), new JsonEncoder());
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/cache', Uuid::generate());
        $key = $facade->probeVideo($job, '/media/source.mkv', 1);
        $this->expectException(NativeExecutionException::class);
        $facade->readResult($key);
    }
}
