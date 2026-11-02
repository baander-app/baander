<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\CompletedVideoArtifact;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\CompletedVideoArtifactPort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use Throwable;

final readonly class CompletedVideoArtifactAdapter implements CompletedVideoArtifactPort
{
    public function __construct(
        private TranscodeJobRepositoryInterface $jobs,
        private VideoPortInterface $videos,
        private TranscodeExecutionOwnershipPort $ownership,
        private TranscodeStoragePortInterface $storage,
        private PublishedPresentationReader $presentations,
    ) {
    }

    public function findComplete(Uuid $owner, Uuid $video, string $sourceHash, int $videoStreamIndex, ?int $audioStreamIndex): array
    {
        $source = $this->videos->findByUuid($video);
        if ($source === null || $source->getHash() !== $sourceHash
            || !array_key_exists('subtitleStreams', $source->getProbe()) || $source->getProbe()['subtitleStreams'] !== []) {
            return [];
        }
        $artifacts = [];
        foreach ($this->jobs->findActiveByOwnerAndVideo($owner, $video) as $job) {
            $selection = $job->getPresentationSelection();
            if ($selection === null || $selection->sourceHash !== $sourceHash || $selection->videoStreamIndex !== $videoStreamIndex
                || $selection->audioStreamIndex !== $audioStreamIndex || $selection->outputContainer !== 'mp4') {
                continue;
            }
            $runtime = $this->ownership->find($job->getId());
            if ($runtime === null || $runtime->outcome !== 'complete' || $runtime->outputDirectory === null) {
                continue;
            }
            try {
                $scoped = $this->storage->forGeneration($video, $job->getQualityTierName(), $runtime->generation, $job->getId());
                $directory = $scoped->resolveJobDirectory($video, $job->getQualityTierName());
                if ($directory !== $runtime->outputDirectory || !$scoped->exists($directory . '/generation.json')) {
                    continue;
                }
                $metadata = json_decode((string) file_get_contents($directory . '/generation.json'), true, 32, JSON_THROW_ON_ERROR);
                if (($metadata['complete'] ?? null) !== true || ($metadata['generation'] ?? null) !== $runtime->generation
                    || ($metadata['presentationSelection'] ?? null) !== $selection->jsonSerialize()
                    || ($metadata['startTime'] ?? -1) != 0
                    || ($metadata['sourceSize'] ?? null) !== @filesize($source->getPath())
                    || ($metadata['sourceModified'] ?? null) !== @filemtime($source->getPath())
                    || !is_int($metadata['bitDepth'] ?? null) || !is_bool($metadata['hdr'] ?? null) || !is_string($metadata['pixelFormat'] ?? null)) {
                    continue;
                }
                $presentation = $this->presentations->read($directory);
                $videoTrack = $presentation?->track(MediaTrack::Video);
                $audioTrack = $presentation?->track(MediaTrack::Audio);
                if ($presentation === null || !$presentation->complete || $videoTrack === null
                    || (($audioStreamIndex !== null) !== ($audioTrack !== null))) {
                    continue;
                }
                $valid = true;
                foreach ($presentation->tracks as $track) {
                    foreach ([$track->initFile, ...array_map(static fn ($segment): string => $segment->file, $track->segments)] as $file) {
                        $path = $directory . '/' . $file;
                        if (!$scoped->exists($path) || !is_file($path) || filesize($path) <= 0) {
                            $valid = false;
                            break 2;
                        }
                    }
                }
                if (!$valid) {
                    continue;
                }
                $artifacts[] = new CompletedVideoArtifact(
                    '/api/transcode/artifacts/' . $job->getPublicId()->toString() . '/' . $runtime->generation . '/master.m3u8',
                    'mp4',
                    (string) ($metadata['videoCodec'] ?? ''),
                    $audioTrack === null ? null : (string) ($metadata['audioCodec'] ?? ''),
                    $job->getWidth(),
                    $job->getHeight(),
                    $audioTrack === null ? null : (int) ($metadata['audioChannels'] ?? 2),
                    $videoStreamIndex,
                    $audioStreamIndex,
                    $metadata['bitDepth'],
                    $metadata['hdr'],
                    'hls',
                    $metadata['pixelFormat'],
                    $job->getPublicId(),
                    $runtime->generation,
                );
            } catch (Throwable) {
                continue;
            }
        }
        return $artifacts;
    }
}
