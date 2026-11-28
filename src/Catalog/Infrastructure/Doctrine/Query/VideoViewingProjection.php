<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Activity\Application\DTO\ViewingState;
use App\Activity\Application\Port\ViewingStatePortInterface;
use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * @phpstan-import-type FileLink from VideoWorkAvailability
 * @phpstan-import-type Version from MemberVideoCatalogReadInterface
 * @phpstan-import-type Extra from MemberVideoCatalogReadInterface
 * @phpstan-import-type TitleViewing from MemberVideoCatalogReadInterface
 */
final readonly class VideoViewingProjection
{
    private const int BATCH_SIZE = 100;

    public function __construct(private ViewingStatePortInterface $viewing) {}

    /**
     * @param list<string> $episodeIds
     * @param list<string> $movieIds
     * @param array<string, list<FileLink>> $files
     */
    public function state(?Uuid $memberId, array $episodeIds, array $movieIds, array $files): ViewingState
    {
        if ($memberId === null || ($episodeIds === [] && $movieIds === [] && $files === [])) {
            return new ViewingState([], [], []);
        }
        $videos = [];
        foreach ($files as $links) {
            foreach ($links as $link) {
                $videos[$link['video_id']] = Uuid::fromString($link['video_id']);
            }
        }
        $chunks = array_chunk(array_values($videos), self::BATCH_SIZE);
        $state = $this->viewing->forMember(
            $memberId,
            array_map(Uuid::fromString(...), $episodeIds),
            array_map(Uuid::fromString(...), $movieIds),
            $chunks[0] ?? [],
        );
        $resumes = $state->resumes;
        foreach (array_slice($chunks, 1) as $chunk) {
            $resumes += $this->viewing->forMember($memberId, videoIds: $chunk)->resumes;
        }

        return new ViewingState($state->watchedEpisodeIds, $state->watchedMovieIds, $resumes);
    }

    /**
     * @param list<FileLink> $links
     * @return TitleViewing
     */
    public function title(array $links, ViewingState $state, bool $watched): array
    {
        $versions = [];
        $latest = null;
        $default = null;
        foreach ($links as $link) {
            if ($link['extra_kind'] !== null) {
                continue;
            }
            $stateForVideo = $state->resumes[$link['video_id']] ?? null;
            $version = self::file($link, $state);
            $versions[] = $version;
            $playedAt = $stateForVideo?->lastPlayedAt;
            if ($default === null || ($playedAt !== null && ($latest === null || $playedAt > $latest))) {
                $default = $version;
                $latest = $playedAt;
            }
        }

        $progress = null;
        if ($default !== null && $default['positionSeconds'] !== null && $default['duration'] > 0) {
            $fraction = $default['positionSeconds'] / $default['duration'];
            if ($fraction >= 0.02 && $fraction <= 0.9) {
                $progress = $fraction;
            }
        }

        return [
            'watched' => $watched,
            'progress' => $progress,
            'defaultVideoPublicId' => $default['publicId'] ?? null,
            'videos' => $versions,
        ];
    }

    /**
     * @param list<FileLink> $links
     * @return list<Extra>
     */
    public function extras(array $links, ViewingState $state): array
    {
        $extras = [];
        foreach ($links as $link) {
            if ($link['extra_kind'] !== null) {
                $extras[] = self::file($link, $state) + [
                    'placementPublicId' => $link['placement_public_id'],
                    'videoPublicId' => $link['public_id'],
                    'kind' => $link['extra_kind'],
                ];
            }
        }

        return $extras;
    }

    /**
     * @param FileLink $link
     * @return Version
     */
    private static function file(array $link, ViewingState $state): array
    {
        $resume = $state->resumes[$link['video_id']] ?? null;

        return [
            'publicId' => $link['public_id'],
            'width' => $link['width'],
            'height' => $link['height'],
            'duration' => $link['duration'],
            'videoBitrate' => $link['video_bitrate'],
            'positionSeconds' => $resume?->positionSeconds,
            'lastPlayedAt' => $resume?->lastPlayedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
