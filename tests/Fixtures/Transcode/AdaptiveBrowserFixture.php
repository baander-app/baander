<?php

declare(strict_types=1);

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use App\Transcode\Interface\Controller\StreamManifestController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use PHPUnit\Framework\MockObject\Generator\Generator;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Disposable browser qualification support; no application kernel or external services. */
final class AdaptiveBrowserFixture
{
    public static function directory(): string
    {
        $directory = getenv('BAANDER_ADAPTIVE_DIRECTORY');
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('BAANDER_ADAPTIVE_DIRECTORY must identify a disposable existing directory.');
        }
        return $directory;
    }

    /** @param array<array-key, mixed> $value */
    public static function writeJson(string $path, array $value): void
    {
        file_put_contents($path . '.part', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        rename($path . '.part', $path);
    }

    /** @return array<array-key, mixed> */
    public static function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function status(array $state): array
    {
        $tiers = [];
        foreach ($state['jobs'] as $name => $entry) {
            $presentation = (new PublishedPresentationReader())->read($entry['directory']);
            $video = $presentation?->track(MediaTrack::Video);
            $audio = $presentation?->track(MediaTrack::Audio);
            $producerFile = self::directory() . '/producer-' . $name . '.json';
            $producer = is_file($producerFile) ? self::json($producerFile) : [];
            $tiers[$name] = ['jobId' => $entry['job']->getPublicId()->toString(),
                'videoSegments' => count($video->segments ?? []), 'audioSegments' => count($audio->segments ?? []),
                'videoEnd' => $video?->end() ?? 0, 'audioEnd' => $audio?->end() ?? 0,
                'complete' => $presentation->complete ?? false,
                'producerRunning' => $producer['running'] ?? false, 'producerExitCode' => $producer['exitCode'] ?? null];
        }
        return ['ready' => count(array_filter($tiers, static fn (array $tier): bool => $tier['videoSegments'] > 0 && $tier['audioSegments'] > 0)) === 2,
            'complete' => count(array_filter($tiers, static fn (array $tier): bool => $tier['complete'])) === 2, 'tiers' => $tiers];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{TranscodeStreamingService, StreamManifestController, SignedStreamRequest}
     */
    public static function services(array $state): array
    {
        $generator = new Generator();
        $repository = $generator->testDouble(TranscodeJobRepositoryInterface::class, false);
        assert($repository instanceof TranscodeJobRepositoryInterface);
        $repository->method('findByPublicId')->willReturnCallback(static function (PublicId $id) use ($state): ?TranscodeJob {
            foreach ($state['jobs'] as $entry) {
                if ($entry['job']->getPublicId()->equals($id)) {
                    return $entry['job'];
                }
            }
            return null;
        });
        $ownership = $generator->testDouble(TranscodeExecutionOwnershipPort::class, false);
        assert($ownership instanceof TranscodeExecutionOwnershipPort);
        $ownership->method('find')->willReturnCallback(static function (Uuid $id) use ($state): ?TranscodeRuntimeSnapshot {
            foreach ($state['jobs'] as $entry) {
                if ($entry['job']->getId()->equals($id)) {
                    $presentation = (new PublishedPresentationReader())->read($entry['directory']);
                    return new TranscodeRuntimeSnapshot($id, $entry['sessionId'], 'browser-fixture',
                        new DesiredTranscodeState(AudioProfile::streamingStereo(), 'running', 0), 1, 1, 1,
                        $presentation?->complete ? 'complete' : 'ready', $entry['directory'], null, true);
                }
            }
            return null;
        });
        $auth = new HmacStreamAuthAdapter('disposable-baander-adaptive-fixture', $state['origin']);
        $streaming = new TranscodeStreamingService($repository, new TranscodeFileStorage(new SegmentFileResolver(self::directory())),
            new ManifestGenerator(), new DashManifestGenerator(), new QualityLadderRenderer(), $auth, $ownership, new PublishedPresentationReader());
        $publicView = $generator->testDouble(TranscodePublicViewInterface::class, false);
        assert($publicView instanceof TranscodePublicViewInterface);
        $publicView->method('videoId')->willReturn($state['videoId']);
        $playback = $generator->testDouble(PlaybackPortInterface::class, false);
        $sleeper = $generator->testDouble(SleeperInterface::class, false);
        assert($playback instanceof PlaybackPortInterface);
        assert($sleeper instanceof SleeperInterface);
        $signed = new SignedStreamRequest($auth);
        return [$streaming, new StreamManifestController($streaming, $signed, $playback, $publicView, $sleeper), $signed];
    }
}
