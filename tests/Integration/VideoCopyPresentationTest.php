<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\NativeExecutionFixture;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Swoole\LoopLockRenewalTimerInterface;
use App\Transcode\Infrastructure\Swoole\NativeProcessSpawner;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class VideoCopyPresentationTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function audioSelections(): iterable
    {
        yield 'video and AAC' => [true];
        yield 'video only' => [false];
    }

    #[DataProvider('audioSelections')]
    public function testOwnedCopyPreservesPacketsAndSeeksFromAnIrregularKeyframe(bool $hasAudio): void
    {
        $root = sys_get_temp_dir() . '/baander-video-copy-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $source = $root . '/source.mkv';
        $native = null;
        try {
            $fixture = new Process(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=25',
                ...($hasAudio ? ['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000'] : []),
                '-t', '17.37', '-c:v', 'libx264', '-threads', '1', '-bf', '0', '-g', '1000', '-keyint_min', '1000', '-sc_threshold', '0',
                '-force_key_frames', '0,2.92,8.20,11.16,14.76', '-color_primaries', 'bt709', '-color_trc', 'bt709', '-colorspace', 'bt709', '-c:a', 'aac', '-output_ts_offset', '3.25', $source]);
            $fixture->mustRun();
            $profile = new DeploymentProfile('household');
            $native = new NativeExecutionFixture('baander.app:test', str_repeat('c', 32), profile: $profile);
            $video = Video::create($source, hash_file('xxh3', $source));
            $selection = new PresentationSelection('copy', $video->getHash(), 0, $hasAudio ? 1 : null, 'mp4');
            $job = TranscodeJob::createCopy($video->getId(), $root . '/cache', Uuid::generate(), $selection, 320, 180);
            $session = TranscodeSession::create($job->getOwnerUserId(), $job->getId(), $video->getId(), AudioProfile::streamingStereo());
            $claim = $this->claim($job, $session, $selection, 1, 0.0);
            $outcomes = [];
            $jobs = $this->createStub(TranscodeJobPortInterface::class);
            $jobs->method('findByUuid')->willReturnCallback(static function () use (&$job): TranscodeJob { return TranscodeJob::reconstitute(clone $job->getState()); });
            $jobs->method('save')->willReturnCallback(static function (TranscodeJob $saved) use (&$job): void { $job = $saved; });
            $sessions = $this->createStub(TranscodeSessionPortInterface::class);
            $sessions->method('findByUuid')->willReturn($session);
            $videos = $this->createStub(VideoRepositoryInterface::class);
            $videos->method('findByUuid')->willReturn($video);
            $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
            $ownership->method('refresh')->willReturnCallback(static function () use (&$claim): TranscodeExecutionClaim { return $claim; });
            $ownership->method('renew')->willReturn(true);
            $ownership->method('release')->willReturn(true);
            $ownership->method('withOwnership')->willReturnCallback(static function (TranscodeExecutionClaim $attempt, callable $operation): bool { $operation(); return true; });
            $ownership->method('observe')->willReturnCallback(static function (TranscodeExecutionClaim $attempt, string $outcome) use (&$outcomes): bool { $outcomes[] = $outcome; return true; });
            $timer = $this->createStub(LoopLockRenewalTimerInterface::class);
            $timer->method('tick')->willReturn(1);
            $storage = new TranscodeFileStorage(new SegmentFileResolver($root . '/cache'));
            $encoder = static fn() => throw new \LogicException('Copy must not resolve an encoder');
            $logger = new NullLogger();
            $manager = new TranscodeStreamManager($this->createStub(SegmentAvailabilityInterface::class), $storage, $encoder, $logger, new NativeProcessSpawner($native->client));
            $worker = new TranscodeSessionSubscriber(new MediaExecutionPolicy($profile), $jobs, $sessions, $storage,
                new TranscodeProcessPool($native->client, $logger, new JsonEncoder(), new DeploymentProfile('full')), $encoder, $videos, $logger, $ownership, $ownership, $manager, $timer);
            $worker->run($claim, static fn(): bool => false);
            self::assertSame(TranscodeStatus::Completed, $job->getStatus(), json_encode($outcomes));
            self::assertContains('ready', $outcomes);
            self::assertSame('complete', array_last($outcomes));
            $directory = $job->getOutputDirectory();
            $presentation = (new PublishedPresentationReader())->read($directory);
            self::assertNotNull($presentation);
            self::assertTrue($presentation->complete);
            self::assertSame($hasAudio, $presentation->track(MediaTrack::Audio) !== null);
            $track = $presentation->track(MediaTrack::Video);
            self::assertNotNull($track);
            self::assertCount(3, $track->segments);
            self::assertEqualsWithDelta(8.2, $track->segments[0]->duration / $track->timescale, .001);
            self::assertSame($this->packetHashes($source), $this->packetHashes($directory . '/manifest.mpd'));
            $metadata = json_decode(file_get_contents($directory . '/generation.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertGreaterThan(17.3, $metadata['duration']);
            self::assertLessThan(17.5, $metadata['duration']);
            self::assertSame(0, $metadata['startTime']);
            $repository = $this->createStub(TranscodeJobRepositoryInterface::class);
            $repository->method('findByPublicId')->willReturnCallback(static function () use (&$job): TranscodeJob { return $job; });
            $ownership->method('find')->willReturnCallback(static function () use (&$claim, &$job): TranscodeRuntimeSnapshot {
                return new TranscodeRuntimeSnapshot($job->getId(), $claim->runtime->sessionId, $claim->runtime->requestId,
                    $claim->runtime->desired, $claim->runtime->desiredRevision, $claim->runtime->desiredRevision,
                    $claim->runtime->generation, 'complete', $job->getOutputDirectory(), null);
            });
            $streaming = new TranscodeStreamingService($repository, $storage, new ManifestGenerator(), new DashManifestGenerator(),
                new QualityLadderRenderer(), new HmacStreamAuthAdapter('copy-presentation-proof'), $ownership, new PublishedPresentationReader());
            $manifest = $streaming->getMasterManifest($video->getId(), [['jobId' => $job->getPublicId()->toString(), 'generation' => 1]], time() + 60);
            self::assertStringContainsString('RESOLUTION=320x180', $manifest);
            self::assertStringContainsString('avc1.', $manifest);
            $media = $streaming->getMediaManifest($job->getPublicId(), MediaTrack::Video, 1, time() + 60);
            self::assertStringContainsString('#EXT-X-ENDLIST', $media);
            self::assertStringContainsString('#EXTINF:8.200', $media);

            $claim = $this->claim($job, $session, $selection, 2, 7.0);
            $outcomes = [];
            $worker->run($claim, static fn(): bool => false);
            self::assertSame('complete', array_last($outcomes), json_encode($outcomes));
            $metadata = json_decode(file_get_contents($job->getOutputDirectory() . '/generation.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertEqualsWithDelta(0.0, $metadata['startTime'], .001);
            $seek = (new PublishedPresentationReader())->read($job->getOutputDirectory());
            self::assertNotNull($seek);
            self::assertTrue($seek->covers(7.0 - $metadata['startTime'], $hasAudio));

            foreach ([0.0, 0.001, 0.005] as $index => $offset) {
                $position = 8.2 + $offset;
                $claim = $this->claim($job, $session, $selection, 3 + $index, $position, 'paused');
                $outcomes = [];
                $worker->run($claim, static function () use (&$outcomes): bool {
                    /** @var list<string> $outcomes */
                    return in_array('ready', $outcomes, true);
                });
                self::assertContains('ready', $outcomes, json_encode($outcomes));
                $metadata = json_decode(file_get_contents($job->getOutputDirectory() . '/generation.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertEqualsWithDelta(2.92, $metadata['startTime'], .001);
                $seek = (new PublishedPresentationReader())->read($job->getOutputDirectory());
                self::assertNotNull($seek);
                foreach ([MediaTrack::Video, ...($hasAudio ? [MediaTrack::Audio] : [])] as $kind) {
                    $track = $seek->track($kind);
                    self::assertNotNull($track);
                    self::assertTrue($track->covers($position - $metadata['startTime']));
                    $stream = $kind === MediaTrack::Video ? 'v:0' : 'a:0';
                    $original = $this->packetHashes($source, $stream);
                    $copied = $this->packetHashes($job->getOutputDirectory() . '/manifest.mpd', $stream);
                    self::assertNotEmpty($copied);
                    $first = array_search($copied[0], $original, true);
                    self::assertNotFalse($first);
                    self::assertSame(array_slice($original, $first), $copied);
                }
            }
        } finally {
            $native?->close();
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function claim(TranscodeJob $job, TranscodeSession $session, PresentationSelection $selection, int $generation, float $position, string $status = 'running'): TranscodeExecutionClaim
    {
        return new TranscodeExecutionClaim(new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), Uuid::generate()->toString(),
            new DesiredTranscodeState(AudioProfile::streamingStereo(), status: $status, position: $position, presentationSelection: $selection), $generation, 0, $generation, 'pending', null, null, true),
            new MediaWorkerIdentity('baander.app:test', str_repeat('c', 32), 'media-copy-0', 1), str_repeat('d', 64));
    }

    /** @return list<string> */
    private function packetHashes(string $path, string $stream = 'v:0'): array
    {
        $probe = new Process(['/usr/bin/ffprobe', '-v', 'error', '-select_streams', $stream, '-show_packets', '-show_data_hash', 'sha256',
            '-show_entries', 'packet=data_hash', '-of', 'json', $path]);
        $probe->mustRun();
        return array_column(json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR)['packets'], 'data_hash');
    }
}
