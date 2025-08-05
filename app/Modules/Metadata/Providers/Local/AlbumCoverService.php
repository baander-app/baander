<?php

namespace App\Modules\Metadata\Providers\Local;

use App\Jobs\Library\Music\SaveAlbumCoverJob;
use App\Models\Album;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class AlbumCoverService
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;
    
    /**
     * Queue cover extraction jobs for albums without covers
     */
    public function queueMissingCovers(array $options = []): array
    {
        $albums = $this->findAlbumsWithoutCovers($options);

        if ($albums->isEmpty()) {
            return [
                'found' => 0,
                'queued' => 0,
                'skipped' => 0,
            ];
        }

        return $this->queueCoverJobs($albums, $options);
    }

    /**
     * Find albums that need cover processing
     */
    public function findAlbumsWithoutCovers(array $options = []): Collection
    {
        $query = Album::query()
            ->with(['cover', 'songs' => function ($query) {
                $query->limit(1); // We only need one song per album for cover extraction
            }]);

        // Filter by library if specified
        if (isset($options['library_id'])) {
            $query->where('library_id', $options['library_id']);
        }

        // Only get albums without covers unless force is specified
        if (!($options['force'] ?? false)) {
            $query->whereDoesntHave('cover');
        }

        // Apply limit if specified
        if (isset($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        return $query->get();
    }

    /**
     * Queue cover extraction jobs for the given albums
     */
    public function queueCoverJobs(Collection $albums, array $options = []): array
    {
        $queued = 0;
        $skipped = 0;
        $force = $options['force'] ?? false;

        foreach ($albums as $album) {
            if ($this->shouldSkipAlbum($album, $force)) {
                $skipped++;
                continue;
            }

            $this->queueCoverJob($album, $force);
            $queued++;
        }

        $this->logger->info('Album cover jobs queued', [
            'total_albums' => $albums->count(),
            'queued' => $queued,
            'skipped' => $skipped,
        ]);

        return [
            'found' => $albums->count(),
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * Queue a single cover extraction job for an album
     */
    public function queueCoverJob(Album $album, bool $force = false): void
    {
        // Mark as queued to prevent duplicate jobs
        $cacheKey = "album_cover_queued_{$album->id}";
        cache()->put($cacheKey, true, now()->addMinutes(30));

        SaveAlbumCoverJob::dispatch($album, $force);

        $this->logger->debug('Album cover job queued', [
            'album_id' => $album->id,
            'album_title' => $album->title,
            'force' => $force,
        ]);
    }

    /**
     * Check if an album should be skipped for cover processing
     */
    private function shouldSkipAlbum(Album $album, bool $force): bool
    {
        // Skip if album has no songs (can't extract cover)
        if ($album->songs->isEmpty()) {
            $this->logger->debug('Skipping album without songs', [
                'album_id' => $album->id,
                'album_title' => $album->title,
            ]);
            return true;
        }

        // Check if job is already queued to prevent duplicates
        $cacheKey = "album_cover_queued_{$album->id}";
        if (cache()->has($cacheKey) && !$force) {
            $this->logger->debug('Skipping already queued album', [
                'album_id' => $album->id,
                'album_title' => $album->title,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Get statistics about albums and their cover status
     */
    public function getCoverStatistics(?int $libraryId = null): array
    {
        $query = Album::query();

        if ($libraryId) {
            $query->where('library_id', $libraryId);
        }

        $totalAlbums = $query->count();
        $albumsWithCovers = $query->whereHas('cover')->count();
        $albumsWithoutCovers = $totalAlbums - $albumsWithCovers;

        return [
            'total_albums' => $totalAlbums,
            'albums_with_covers' => $albumsWithCovers,
            'albums_without_covers' => $albumsWithoutCovers,
            'coverage_percentage' => $totalAlbums > 0 ? round(($albumsWithCovers / $totalAlbums) * 100, 2) : 0,
        ];
    }

    /**
     * Check if an album has a cover
     */
    public function albumHasCover(Album $album): bool
    {
        if ($album->cover()->exists()) {
            return true;
        }

        // Check if using media library for covers
        if (method_exists($album, 'getMedia') && $album->getMedia('cover')->isNotEmpty()) {
            return true;
        }

        return false;
    }

    /**
     * Clear queued status for albums (useful for cleanup)
     */
    public function clearQueuedStatus(array $albumIds = []): int
    {
        $cleared = 0;

        if (empty($albumIds)) {
            // Clear all queued statuses (be careful with this)
            $keys = cache()->getRedis()->keys('*album_cover_queued_*');
            foreach ($keys as $key) {
                cache()->forget(str_replace(config('cache.prefix') . ':', '', $key));
                $cleared++;
            }
        } else {
            foreach ($albumIds as $albumId) {
                $cacheKey = "album_cover_queued_{$albumId}";
                if (cache()->has($cacheKey)) {
                    cache()->forget($cacheKey);
                    $cleared++;
                }
            }
        }

        $this->logger->info('Cleared queued status for album covers', ['cleared_count' => $cleared]);

        return $cleared;
    }
}