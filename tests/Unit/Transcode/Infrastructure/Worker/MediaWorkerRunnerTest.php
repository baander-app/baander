<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Worker;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Infrastructure\Worker\MediaWorkerAuthorityInterface;
use App\Transcode\Infrastructure\Worker\MediaWorkerRunner;
use PHPUnit\Framework\TestCase;

final class MediaWorkerRunnerTest extends TestCase
{
    /** @param list<string> $expectedIds */
    #[\PHPUnit\Framework\Attributes\DataProvider('deploymentCapacities')]
    public function testFreshChildrenRespectCapacityAndAreReapedOnDrain(string $profile, int $count, array $expectedIds, int $videoCount): void
    {
        $directory = sys_get_temp_dir() . '/baander-media-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $script = $directory . '/child.php';
        $log = $directory . '/launches';
        file_put_contents($script, '<?php file_put_contents(__DIR__."/launches", json_encode([$argv[1], $argv[2], getmypid(), getenv("BAANDER_NATIVE_CLIENT_ID"), getenv("BAANDER_NATIVE_CLIENT_GENERATION")])."\n", FILE_APPEND | LOCK_EX); sleep(10);');
        $video = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $video->expects(self::never())->method('claim');
        $jobs = [];
        for ($index = 0; $index < 4; ++$index) {
            $jobs[] = new TranscodeRuntimeSnapshot(Uuid::generate(), Uuid::generate(), 'request', new DesiredTranscodeState(\App\Transcode\Domain\ValueObject\AudioProfile::streamingStereo()), 1, 0, 0, 'pending', null, null);
        }
        $video->expects(self::exactly(2))->method('pending')->willReturn($jobs);
        $audio = $this->createMock(AudioRenditionRuntimePort::class);
        $audio->expects(self::never())->method('claim');
        $audioJobs = [];
        foreach (['one', 'two'] as $track) {
            $input = new AudioRenditionInput($track, 'source.flac', '/media/source.flac', \App\Transcode\Application\Port\AudioRenditionFormat::Opus, 128000, '/media/derived');
            $audioJobs[] = new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 0, null, null, null);
        }
        $audio->expects(self::exactly(2))->method('pending')->willReturn($audioJobs);
        $authority = $this->createMock(MediaWorkerAuthorityInterface::class);
        $authority->expects(self::atLeastOnce())->method('assertActive');
        $ready = false;
        $deadline = microtime(true) + 3;
        $fullAt = null;
        try {
            (new MediaWorkerRunner($video, $audio, $authority, $script, deploymentProfile: new \App\Shared\Application\DTO\DeploymentProfile($profile)))->run(
                static function () use ($log, $deadline, &$fullAt, $count): bool {
                    if (is_file($log) && count(file($log)) >= $count) {
                        $fullAt ??= microtime(true);
                    }
                    return ($fullAt !== null && microtime(true) - $fullAt >= 0.15) || microtime(true) >= $deadline;
                },
                static function () use (&$ready): void { $ready = true; },
            );
            self::assertTrue($ready);
            $launches = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log));
            self::assertCount($count, $launches);
            $clientIds = array_column($launches, 3);
            sort($clientIds);
            self::assertSame($expectedIds, $clientIds);
            self::assertSame(['1'], array_values(array_unique(array_column($launches, 4))));
            self::assertCount($count, array_unique(array_column($launches, 1)));
            self::assertSame($videoCount, count(array_filter($launches, static fn (array $launch): bool => $launch[0] === 'video')));
            self::assertSame(1, count(array_filter($launches, static fn (array $launch): bool => $launch[0] === 'audio')));
            foreach ($launches as $launch) {
                self::assertDirectoryDoesNotExist('/proc/' . $launch[2], 'A drained child must be reaped.');
            }
        } finally {
            @unlink($log);
            unlink($script);
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{string,int,list<string>,int}> */
    public static function deploymentCapacities(): iterable
    {
        yield 'full' => ['full', 4, ['media-audio-0', 'media-video-0', 'media-video-1', 'media-video-2'], 3];
        yield 'household' => ['household', 2, ['media-copy-0', 'media-copy-1'], 1];
    }

    public function testSharedSlotGenerationFollowsTheSlotAcrossJobKinds(): void
    {
        $directory = sys_get_temp_dir() . '/baander-media-shared-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $script = $directory . '/child.php';
        $log = $directory . '/launches';
        file_put_contents($script, '<?php file_put_contents(__DIR__."/launches", json_encode([$argv[1], getenv("BAANDER_NATIVE_CLIENT_ID"), getenv("BAANDER_NATIVE_CLIENT_GENERATION"), getmypid()])."\n", FILE_APPEND | LOCK_EX);');
        $runtime = new TranscodeRuntimeSnapshot(Uuid::generate(), Uuid::generate(), 'request', new DesiredTranscodeState(\App\Transcode\Domain\ValueObject\AudioProfile::streamingStereo()), 1, 0, 0, 'pending', null, null);
        $video = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $video->method('pending')->willReturnCallback(static fn (): array => is_file($log) ? [] : [$runtime]);
        $input = new AudioRenditionInput('track', 'source.flac', '/media/source.flac', \App\Transcode\Application\Port\AudioRenditionFormat::Opus, 128000, '/media/derived');
        $audioRuntime = new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 0, null, null, null);
        $audio = $this->createStub(AudioRenditionRuntimePort::class);
        $audio->method('pending')->willReturnCallback(static function () use ($log, $audioRuntime): array {
            if (!is_file($log)) {
                return [];
            }
            $first = json_decode(file($log)[0], true, flags: JSON_THROW_ON_ERROR);
            return is_dir('/proc/' . $first[3]) ? [] : [$audioRuntime];
        });
        $authority = $this->createStub(MediaWorkerAuthorityInterface::class);
        $deadline = hrtime(true) + 3_000_000_000;
        try {
            (new MediaWorkerRunner($video, $audio, $authority, $script, deploymentProfile: new \App\Shared\Application\DTO\DeploymentProfile('household')))->run(
                static fn (): bool => (is_file($log) && count(file($log)) >= 2) || hrtime(true) >= $deadline,
            );
            $launches = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log));
            self::assertSame([['video', 'media-copy-0', '1'], ['audio', 'media-copy-0', '2']], array_map(static fn (array $launch): array => array_slice($launch, 0, 3), $launches));
        } finally {
            @unlink($log);
            unlink($script);
            rmdir($directory);
        }
    }

    public function testSerialAudioJobsReuseOneSlotWithIncreasingLaunchGenerations(): void
    {
        $directory = sys_get_temp_dir() . '/baander-media-generations-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $script = $directory . '/child.php';
        $log = $directory . '/launches';
        file_put_contents($script, '<?php file_put_contents(__DIR__."/launches", json_encode([getenv("BAANDER_NATIVE_CLIENT_ID"), getenv("BAANDER_NATIVE_CLIENT_GENERATION")])."\n", FILE_APPEND | LOCK_EX);');
        $video = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $video->method('pending')->willReturn([]);
        $audio = $this->createStub(AudioRenditionRuntimePort::class);
        $input = new AudioRenditionInput('track', 'source.flac', '/media/source.flac', \App\Transcode\Application\Port\AudioRenditionFormat::Opus, 128000, '/media/derived');
        $audio->method('pending')->willReturn([new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 0, null, null, null)]);
        $authority = $this->createStub(MediaWorkerAuthorityInterface::class);
        $deadline = hrtime(true) + 10_000_000_000;
        try {
            (new MediaWorkerRunner($video, $audio, $authority, $script))->run(static function () use ($log, $deadline): bool {
                return (is_file($log) && count(file($log)) >= 65) || hrtime(true) >= $deadline;
            });
            $launches = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log));
            self::assertCount(65, $launches);
            self::assertSame(['media-audio-0'], array_values(array_unique(array_column($launches, 0))));
            self::assertSame(array_map(strval(...), range(1, 65)), array_column($launches, 1));
        } finally {
            @unlink($log);
            unlink($script);
            rmdir($directory);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fatalConditions')]
    public function testAuthorityLossAndFatalChildCloseAdmissionAndReap(bool $loseAuthority): void
    {
        $directory = sys_get_temp_dir() . '/baander-media-failure-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $script = $directory . '/child.php';
        $pidFile = $directory . '/pid';
        file_put_contents($script, '<?php file_put_contents(__DIR__."/pid", (string) getmypid()); ' . ($loseAuthority ? 'sleep(10);' : 'exit(23);'));
        $video = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $video->method('pending')->willReturn([new TranscodeRuntimeSnapshot(Uuid::generate(), Uuid::generate(), 'request', new DesiredTranscodeState(\App\Transcode\Domain\ValueObject\AudioProfile::streamingStereo()), 1, 0, 0, 'pending', null, null)]);
        $audio = $this->createStub(AudioRenditionRuntimePort::class);
        $audio->method('pending')->willReturn([]);
        $authority = $this->createStub(MediaWorkerAuthorityInterface::class);
        $checks = 0;
        $authority->method('assertActive')->willReturnCallback(static function () use (&$checks, $loseAuthority, $pidFile): void {
            if (++$checks >= 4 && $loseAuthority && is_file($pidFile)) {
                throw new \RuntimeException('authority lost during work');
            }
        });
        $deadline = microtime(true) + 3;
        try {
            try {
                (new MediaWorkerRunner($video, $audio, $authority, $script))->run(static fn (): bool => microtime(true) >= $deadline);
                self::fail('Role continued after execution authority became uncertain.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($loseAuthority ? 'authority lost' : 'exited unexpectedly', $error->getMessage());
            }
            self::assertFileExists($pidFile);
            self::assertDirectoryDoesNotExist('/proc/' . trim(file_get_contents($pidFile)));
        } finally {
            @unlink($pidFile);
            unlink($script);
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function fatalConditions(): iterable
    {
        yield 'authority lost after launch' => [true];
        yield 'nonzero execution exit' => [false];
    }

    public function testAuthorityFailureNeverReportsReadinessOrLaunches(): void
    {
        $video = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $video->expects(self::never())->method('pending');
        $audio = $this->createMock(AudioRenditionRuntimePort::class);
        $authority = $this->createMock(MediaWorkerAuthorityInterface::class);
        $audio->expects(self::never())->method('pending');
        $authority->expects(self::once())->method('assertActive')->willThrowException(new \RuntimeException('authority lost'));
        $this->expectExceptionMessage('authority lost');
        (new MediaWorkerRunner($video, $audio, $authority))->run(static fn (): bool => false, static function (): void { self::fail('Uninitialized role reported readiness.'); });
    }
}
