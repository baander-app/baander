<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class TranscodeProcessPoolTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\TestWith(['remux'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['transcode'])]
    public function testHelpersQueueUntilThePreviousNativeAttemptIsReleased(string $capability): void
    {
        $submitted = [];
        $released = [];
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$submitted): NativeExecutionHandle {
            $submitted[] = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($submitted));
        });
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => 'metadata']);
        $client->method('release')->willReturnCallback(static function (NativeExecutionHandle $handle) use (&$released): bool {
            $released[] = $handle->attempt;
            return true;
        });
        $pool = new TranscodeProcessPool($client, new NullLogger(), new JsonEncoder(), $capability === 'remux' ? new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2) : new DeploymentProfile());
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/cache', Uuid::generate());
        $first = $pool->probeVideo($job, '/media/first.mkv', 1);
        $second = $pool->probeVideo($job, '/media/second.mkv', 2);
        self::assertCount(1, $submitted);
        self::assertNull($pool->readResult($second));
        self::assertSame(['status' => 'ok', 'data' => 'metadata'], $pool->readResult($first));
        self::assertSame([1], $released);
        self::assertCount(2, $submitted);
        self::assertSame('/media/second.mkv', $submitted[1][array_key_last($submitted[1])]);
        self::assertSame(['status' => 'ok', 'data' => 'metadata'], $pool->readResult($second));
        self::assertSame([1, 2], $released);
    }

    public function testShutdownDiscardsQueuedHelpersWithoutLaunchingThem(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->expects(self::once())->method('signal')->willReturn(['state' => 'exited']);
        $client->method('inspect')->willReturn(['state' => 'exited', 'stdout' => '', 'stderr' => '', 'exitCode' => 0, 'cpuSeconds' => 0.0]);
        $client->expects(self::once())->method('release')->willReturn(true);
        $pool = new TranscodeProcessPool($client, new NullLogger(), new JsonEncoder(), new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/cache', Uuid::generate());
        $pool->probeVideo($job, '/media/first.mkv', 1);
        $pool->probeVideo($job, '/media/second.mkv', 2);
        $pool->shutdown();
    }
}
