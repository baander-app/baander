<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentation;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use Throwable;

/** @phpstan-type ArtifactContext array{directory: string, presentation: PublishedPresentation, width: int, height: int, channels: int, targetDuration: float} */
final readonly class CompletedVideoDeliveryAdapter implements CompletedVideoDeliveryPort
{
    public function __construct(
        private TranscodeJobRepositoryInterface $jobs,
        private VideoPortInterface $videos,
        private PlaybackMediaAccessInterface $access,
        private TranscodeStoragePortInterface $storage,
        private PublishedPresentationReader $presentations,
        private ManifestGenerator $manifests,
        private StreamAuthPortInterface $signatures,
    ) {
    }

    public function grant(PublicId $jobId, int $generation, int $expiresAt): bool
    {
        $context = $this->context($jobId, $generation, $expiresAt, false);
        return $context !== null && $this->storage->retainUntil($context['directory'], $expiresAt);
    }

    public function master(PublicId $jobId, int $generation, int $expiresAt): ?string
    {
        $context = $this->context($jobId, $generation, $expiresAt);
        if ($context === null) {
            return null;
        }
        $video = $context['presentation']->track(MediaTrack::Video);
        if ($video === null) {
            return null;
        }
        $audio = $context['presentation']->track(MediaTrack::Audio);
        $groups = $audio === null ? [] : [['language' => 'und', 'name' => 'Audio', 'groupId' => 'audio', 'isDefault' => true,
            'channels' => (string) $context['channels'], 'uri' => $this->url($jobId, $generation, 'tracks/audio/media.m3u8', $expiresAt)]];
        $codecs = $video->codec . ($audio === null ? '' : ',' . $audio->codec);
        $bandwidth = 1;
        foreach ($context['presentation']->tracks as $track) {
            $peak = 0;
            foreach ($track->segments as $segment) {
                $peak = max($peak, (int) ceil(filesize($context['directory'] . '/' . $segment->file) * 8 * $track->timescale / $segment->duration));
            }
            $bandwidth += $peak;
        }
        return $this->manifests->generateMasterManifest(
            ['copy' => $this->url($jobId, $generation, 'tracks/video/media.m3u8', $expiresAt)],
            $groups,
            videoCodecRfc6381: $codecs,
            bandwidths: ['copy' => $bandwidth],
            dimensions: ['copy' => ['width' => $context['width'], 'height' => $context['height']]],
        );
    }

    public function playlist(PublicId $jobId, int $generation, MediaTrack $track, int $expiresAt): ?string
    {
        $context = $this->context($jobId, $generation, $expiresAt);
        if ($context === null) {
            return null;
        }
        $published = $context['presentation']->track($track);
        if ($published === null) {
            return null;
        }
        $segments = [];
        foreach ($published->segments as $segment) {
            $segments[$segment->ordinal] = ['duration' => $segment->duration / $published->timescale,
                'url' => $this->url($jobId, $generation, 'tracks/' . $track->value . '/segment.m4s', $expiresAt, ['index' => $segment->ordinal])];
        }
        return $this->manifests->generateMediaManifest(
            $this->url($jobId, $generation, 'tracks/' . $track->value . '/init.mp4', $expiresAt),
            $segments,
            true,
            targetDuration: max($context['targetDuration'], ceil(max(array_column($segments, 'duration') ?: [1.0]))),
        );
    }

    public function path(PublicId $jobId, int $generation, MediaTrack $track, ?int $index, int $expiresAt): ?string
    {
        $context = $this->context($jobId, $generation, $expiresAt);
        if ($context === null) {
            return null;
        }
        $published = $context['presentation']->track($track);
        if ($published === null) {
            return null;
        }
        if ($index === null) {
            return $context['directory'] . '/' . $published->initFile;
        }
        foreach ($published->segments as $segment) {
            if ($segment->ordinal === $index) {
                return $context['directory'] . '/' . $segment->file;
            }
        }
        return null;
    }

    /** @return ArtifactContext|null */
    private function context(PublicId $jobId, int $generation, int $expiresAt, bool $requireRetention = true): ?array
    {
        if ($generation < 1 || $expiresAt < time()) {
            return null;
        }
        $job = $this->jobs->findByPublicId($jobId);
        $selection = $job?->getPresentationSelection();
        if ($job === null || $job->getOwnerUserId() === null || $selection === null) {
            return null;
        }
        $video = $this->videos->findByUuid($job->getVideoId());
        if ($video === null) {
            return null;
        }
        $this->access->assertVideoAccess($job->getOwnerUserId(), $video->getPublicId());
        try {
            $scoped = $this->storage->forGeneration($job->getVideoId(), $job->getQualityTierName(), $generation, $job->getId());
            $directory = $scoped->resolveJobDirectory($job->getVideoId(), $job->getQualityTierName());
            if (is_link($directory) || ($requireRetention && !$this->storage->hasUnexpiredRetention($directory))) {
                return null;
            }
            $metadataPath = $directory . '/generation.json';
            if (!$scoped->exists($metadataPath) || is_link($metadataPath)) {
                return null;
            }
            $metadata = json_decode((string) file_get_contents($metadataPath), true, 32, JSON_THROW_ON_ERROR);
            if (($metadata['complete'] ?? null) !== true || ($metadata['generation'] ?? null) !== $generation
                || ($metadata['presentationSelection'] ?? null) !== $selection->jsonSerialize() || ($metadata['startTime'] ?? -1) != 0) {
                return null;
            }
            $presentation = $this->presentations->read($directory);
            if ($presentation === null || !$presentation->complete || $presentation->track(MediaTrack::Video) === null) {
                return null;
            }
            foreach ($presentation->tracks as $track) {
                foreach ([$track->initFile, ...array_map(static fn ($segment): string => $segment->file, $track->segments)] as $file) {
                    $path = $directory . '/' . $file;
                    if (!$scoped->exists($path) || is_link($path) || !is_file($path) || filesize($path) < 1) {
                        return null;
                    }
                }
            }
            return ['directory' => $directory, 'presentation' => $presentation, 'width' => $job->getWidth(), 'height' => $job->getHeight(),
                'channels' => (int) ($metadata['audioChannels'] ?? 2), 'targetDuration' => (float) ($metadata['hlsTargetDuration'] ?? 7)];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, int> $query */
    private function url(PublicId $jobId, int $generation, string $resource, int $expiresAt, array $query = []): string
    {
        $path = '/api/transcode/artifacts/' . $jobId->toString() . '/' . $generation . '/' . $resource;
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        return $this->signatures->signUrlUntil($path, $expiresAt)['url'];
    }
}
