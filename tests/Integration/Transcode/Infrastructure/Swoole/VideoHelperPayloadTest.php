<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Swoole\TranscodePoolWorker;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class VideoHelperPayloadTest extends TestCase
{
    public function testMediaHelpersUseGenerationSpecificKeysAndStatelessPayloads(): void
    {
        $messages = [];
        $pool = $this->createStub(CpuProcessPoolInterface::class);
        $pool->method('dispatch')->willReturnCallback(static function (string $payload, string $key) use (&$messages): void {
            $messages[$key] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        });
        $facade = new TranscodeProcessPool($pool, new NullLogger(), new JsonEncoder());
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/cache', \App\Shared\Domain\Model\Uuid::generate());
        $first = $facade->probeVideo($job, '/media/source.mkv', 1);
        $replacement = $facade->probeVideo($job, '/media/source.mkv', 2);
        $subtitle = $facade->extractSubtitles($job, '/media/source.mkv', 'en', '/cache/generation-2/full.vtt', 2);
        self::assertNotSame($first, $replacement);
        self::assertStringEndsWith(':1', $first);
        self::assertStringEndsWith(':2', $replacement);
        self::assertStringEndsWith(':2:en', $subtitle);
        self::assertSame(['type' => 'probe_video', 'source_path' => '/media/source.mkv'], $messages[$replacement]);
        self::assertSame('/cache/generation-2/full.vtt', $messages[$subtitle]['output_path']);
        self::assertSame(['probe_video', 'extract_subtitles'], (new TranscodePoolWorker())->supportedTypes());
    }
}
