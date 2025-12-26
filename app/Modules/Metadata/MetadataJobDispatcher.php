<?php

namespace App\Modules\Metadata;

use App\Jobs\Library\Music\{SyncAlbumJob, SyncArtistJob, SyncSongMetadataJob};
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Support\Collection;

class MetadataJobDispatcher
{
    public function __construct(
        private readonly int    $defaultBatchSize = 10,
        private readonly string $defaultQueue = 'default',
    )
    {
    }

    public function syncMixed(
        array     $albumIds = [],
        array     $songIds = [],
        array     $artistIds = [],
        ?int      $libraryId = null,
        bool      $forceUpdate = false,
        ?int      $batchSize = null,
        ?string   $queueName = null,
        bool      $includeSongs = false,
        bool      $includeArtists = false,
        ?callable $progressCallback = null,
    ): int
    {
        $totalJobs = 0;

        if (!empty($albumIds)) {
            $totalJobs += $this->syncAlbums(
                $albumIds,
                $forceUpdate,
                $batchSize,
                $queueName,
                $includeSongs,
                $includeArtists,
                $progressCallback,
            );
        }

        if (!empty($songIds)) {
            $totalJobs += $this->syncSongs(
                $songIds,
                $forceUpdate,
                $batchSize,
                $queueName,
                $progressCallback,
            );
        }

        if (!empty($artistIds)) {
            $totalJobs += $this->syncArtists(
                $artistIds,
                $forceUpdate,
                $batchSize,
                $queueName,
                $progressCallback,
            );
        }

        if ($libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            $totalJobs += $this->syncLibrary(
                $libraryId,
                $forceUpdate,
                $batchSize,
                $queueName,
                $includeSongs,
                $includeArtists,
                $progressCallback,
            );
        }

        return $totalJobs;
    }

    public function syncAlbums(
        array     $albumIds,
        bool      $forceUpdate = false,
        ?int      $batchSize = null,
        ?string   $queueName = null,
        bool      $includeSongs = false,
        bool      $includeArtists = false,
        ?callable $progressCallback = null,
        string    $syncType = 'general', // 'general', 'identifier', 'full', 'legacy'
    ): int
    {
        $batchSize = $batchSize ?? $this->defaultBatchSize;
        $queueName = $queueName ?? $this->defaultQueue;

        $query = Album::query()->whereIn('id', $albumIds);
        $totalAlbums = $query->count();

        if ($totalAlbums === 0) {
            return 0;
        }

        $jobCount = 0;
        $processed = 0;

        $query->chunk($batchSize, function (Collection $albums) use (
            $forceUpdate,
            $queueName,
            $syncType,
            &$jobCount,
            &$processed,
            $includeSongs,
            $includeArtists,
            $progressCallback,
            $totalAlbums,
        ) {
            foreach ($albums as $album) {
                // Dispatch album sync job
                $job = match ($syncType) {
                    'identifier' => SyncAlbumJob::syncIdentifierBased($album->id, $forceUpdate),
                    'full' => SyncAlbumJob::syncAll($album->id, $forceUpdate),
                    default => SyncAlbumJob::syncGeneral($album->id, $forceUpdate)
                };

                $job->onQueue($queueName);
                dispatch($job);
                $jobCount++;

                if ($includeSongs) {
                    foreach ($album->songs as $song) {
                        SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                            ->onQueue($queueName);
                        $jobCount++;
                    }
                }

                if ($includeArtists) {
                    foreach ($album->artists as $artist) {
                        SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
                            ->onQueue($queueName)
                            ->dispatch($artist->id, $forceUpdate);
                        $jobCount++;
                    }
                }

                $processed++;
                if ($progressCallback) {
                    $progressCallback($processed, $totalAlbums, 'albums');
                }
            }
        });

        return $jobCount;
    }

    public function syncSongs(
        array     $songIds,
        bool      $forceUpdate = false,
        ?int      $batchSize = null,
        ?string   $queueName = null,
        ?callable $progressCallback = null,
    ): int
    {
        $batchSize = $batchSize ?? $this->defaultBatchSize;
        $queueName = $queueName ?? $this->defaultQueue;

        $query = Song::query()->whereIn('id', $songIds);
        $totalSongs = $query->count();

        if ($totalSongs === 0) {
            return 0;
        }

        $jobCount = 0;
        $processed = 0;

        $query->chunk($batchSize, function (Collection $songs) use (
            $forceUpdate,
            $queueName,
            &$jobCount,
            &$processed,
            $progressCallback,
            $totalSongs,
        ) {
            foreach ($songs as $song) {
                SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                    ->onQueue($queueName);
                $jobCount++;

                $processed++;
                if ($progressCallback) {
                    $progressCallback($processed, $totalSongs, 'songs');
                }
            }
        });

        return $jobCount;
    }

    public function syncArtists(
        array     $artistIds,
        bool      $forceUpdate = false,
        ?int      $batchSize = null,
        ?string   $queueName = null,
        ?callable $progressCallback = null,
        string    $syncType = 'general', // 'general', 'identifier', 'full'
    ): int
    {
        $batchSize = $batchSize ?? $this->defaultBatchSize;
        $queueName = $queueName ?? $this->defaultQueue;

        $query = Artist::query()->whereIn('id', $artistIds);
        $totalArtists = $query->count();

        if ($totalArtists === 0) {
            return 0;
        }

        $jobCount = 0;
        $processed = 0;

        $query->chunk($batchSize, function (Collection $artists) use (
            $forceUpdate,
            $queueName,
            $syncType,
            &$jobCount,
            &$processed,
            $progressCallback,
            $totalArtists,
        ) {
            foreach ($artists as $artist) {
                $job = match ($syncType) {
                    'identifier' => SyncArtistJob::syncIdentifierBased($artist->id, $forceUpdate),
                    'full' => SyncArtistJob::syncAll($artist->id, $forceUpdate),
                    default => SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
                };

                $job->onQueue($queueName);
                dispatch($job);
                $jobCount++;

                $processed++;
                if ($progressCallback) {
                    $progressCallback($processed, $totalArtists, 'artists');
                }
            }
        });

        return $jobCount;
    }

    public function syncLibrary(
        int       $libraryId,
        bool      $forceUpdate = false,
        ?int      $batchSize = null,
        ?string   $queueName = null,
        bool      $includeSongs = false,
        bool      $includeArtists = false,
        ?callable $progressCallback = null,
        string    $albumSyncType = 'general',
        string    $artistSyncType = 'general',
    ): int
    {
        $batchSize = $batchSize ?? $this->defaultBatchSize;
        $queueName = $queueName ?? $this->defaultQueue;

        $totalJobs = 0;

        // Sync all albums in the library
        $albumQuery = Album::query()->where('library_id', $libraryId);
        $totalAlbums = $albumQuery->count();

        if ($totalAlbums > 0) {
            $processed = 0;
            $albumQuery->chunk($batchSize, function (Collection $albums) use (
                $forceUpdate,
                $queueName,
                $albumSyncType,
                $artistSyncType,
                &$totalJobs,
                &$processed,
                $includeSongs,
                $includeArtists,
                $progressCallback,
                $totalAlbums,
            ) {
                foreach ($albums as $album) {
                    // Dispatch album sync job
                    $albumJob = match ($albumSyncType) {
                        'general' => SyncAlbumJob::syncGeneral($album->id, $forceUpdate),
                        'identifier' => SyncAlbumJob::syncIdentifierBased($album->id, $forceUpdate),
                        'full' => SyncAlbumJob::syncAll($album->id, $forceUpdate),
                        'legacy' => SyncAlbumJob::syncLegacy($album->id, $forceUpdate),
                        default => SyncAlbumJob::syncGeneral($album->id, $forceUpdate)
                    };

                    $albumJob->onQueue($queueName);
                    dispatch($albumJob);
                    $totalJobs++;

                    if ($includeSongs) {
                        foreach ($album->songs as $song) {
                            SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                                ->onQueue($queueName);
                            $totalJobs++;
                        }
                    }

                    if ($includeArtists) {
                        foreach ($album->artists as $artist) {
                            $artistJob = match ($artistSyncType) {
                                'general' => SyncArtistJob::syncGeneral($artist->id, $forceUpdate),
                                'identifier' => SyncArtistJob::syncIdentifierBased($artist->id, $forceUpdate),
                                'full' => SyncArtistJob::syncAll($artist->id, $forceUpdate),
                                'legacy' => SyncArtistJob::syncLegacy($artist->id, $forceUpdate),
                                default => SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
                            };

                            $artistJob->onQueue($queueName);
                            dispatch($artistJob);
                            $totalJobs++;
                        }
                    }

                    $processed++;
                    if ($progressCallback) {
                        $progressCallback($processed, $totalAlbums, 'albums');
                    }
                }
            });
        }

        // Sync orphaned songs if requested
        if ($includeSongs) {
            $orphanedSongsQuery = Song::query()
                ->whereHas('album', function ($query) use ($libraryId) {
                    $query->where('library_id', $libraryId);
                })
                ->whereDoesntHave('album');

            $totalOrphanedSongs = $orphanedSongsQuery->count();

            if ($totalOrphanedSongs > 0) {
                $processed = 0;
                $orphanedSongsQuery->chunk($batchSize, function (Collection $songs) use (
                    $forceUpdate,
                    $queueName,
                    &$totalJobs,
                    &$processed,
                    $progressCallback,
                    $totalOrphanedSongs,
                ) {
                    foreach ($songs as $song) {
                        SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                            ->onQueue($queueName);
                        $totalJobs++;

                        $processed++;
                        if ($progressCallback) {
                            $progressCallback($processed, $totalOrphanedSongs, 'orphaned_songs');
                        }
                    }
                });
            }
        }

        // Sync unique artists if requested
        if ($includeArtists) {
            $artistQuery = Artist::query()
                ->whereHas('albums', function ($query) use ($libraryId) {
                    $query->where('library_id', $libraryId);
                });

            $totalArtists = $artistQuery->count();

            if ($totalArtists > 0) {
                $processed = 0;
                $artistQuery->chunk($batchSize, function (Collection $artists) use (
                    $forceUpdate,
                    $queueName,
                    $artistSyncType,
                    &$totalJobs,
                    &$processed,
                    $progressCallback,
                    $totalArtists,
                ) {
                    foreach ($artists as $artist) {
                        $artistJob = match ($artistSyncType) {
                            'general' => SyncArtistJob::syncGeneral($artist->id, $forceUpdate),
                            'identifier' => SyncArtistJob::syncIdentifierBased($artist->id, $forceUpdate),
                            'full' => SyncArtistJob::syncAll($artist->id, $forceUpdate),
                            'legacy' => SyncArtistJob::syncLegacy($artist->id, $forceUpdate),
                            default => SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
                        };

                        $artistJob->onQueue($queueName);
                        dispatch($artistJob);
                        $totalJobs++;

                        $processed++;
                        if ($progressCallback) {
                            $progressCallback($processed, $totalArtists, 'library_artists');
                        }
                    }
                });
            }
        }

        return $totalJobs;
    }

    public function getLibraryStats(int $libraryId): array
    {
        return [
            'albums'         => Album::where('library_id', $libraryId)->count(),
            'songs'          => Song::whereHas('album', function ($query) use ($libraryId) {
                $query->where('library_id', $libraryId);
            })->count(),
            'artists'        => Artist::whereHas('albums', function ($query) use ($libraryId) {
                $query->where('library_id', $libraryId);
            })->count(),
            'orphaned_songs' => Song::whereHas('album', function ($query) use ($libraryId) {
                $query->where('library_id', $libraryId);
            })->whereDoesntHave('album')->count(),
        ];
    }

    public function validateIds(array $albumIds = [], array $songIds = [], array $artistIds = []): array
    {
        $results = [
            'albums'  => ['valid' => [], 'invalid' => []],
            'songs'   => ['valid' => [], 'invalid' => []],
            'artists' => ['valid' => [], 'invalid' => []],
        ];

        if (!empty($albumIds)) {
            $validAlbums = Album::whereIn('id', $albumIds)->pluck('id')->toArray();
            $results['albums']['valid'] = $validAlbums;
            $results['albums']['invalid'] = array_diff($albumIds, $validAlbums);
        }

        if (!empty($songIds)) {
            $validSongs = Song::whereIn('id', $songIds)->pluck('id')->toArray();
            $results['songs']['valid'] = $validSongs;
            $results['songs']['invalid'] = array_diff($songIds, $validSongs);
        }

        if (!empty($artistIds)) {
            $validArtists = Artist::whereIn('id', $artistIds)->pluck('id')->toArray();
            $results['artists']['valid'] = $validArtists;
            $results['artists']['invalid'] = array_diff($artistIds, $validArtists);
        }

        return $results;
    }

    /**
     * Enhanced album sync methods with specific sync types
     */
    public function syncAlbumsGeneral(array $albumIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncAlbums($albumIds, $forceUpdate, $batchSize, $queueName, false, false, null, 'general');
    }

    public function syncAlbumsIdentifierBased(array $albumIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncAlbums($albumIds, $forceUpdate, $batchSize, $queueName, false, false, null, 'identifier');
    }

    public function syncAlbumsFull(array $albumIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncAlbums($albumIds, $forceUpdate, $batchSize, $queueName, false, false, null, 'full');
    }

    public function syncAlbumsLegacy(array $albumIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncAlbums($albumIds, $forceUpdate, $batchSize, $queueName, false, false, null, 'legacy');
    }

    /**
     * Enhanced artist sync methods with specific sync types
     */
    public function syncArtistsGeneral(array $artistIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncArtists($artistIds, $forceUpdate, $batchSize, $queueName, null, 'general');
    }

    public function syncArtistsIdentifierBased(array $artistIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncArtists($artistIds, $forceUpdate, $batchSize, $queueName, null, 'identifier');
    }

    public function syncArtistsFull(array $artistIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncArtists($artistIds, $forceUpdate, $batchSize, $queueName, null, 'full');
    }

    public function syncArtistsLegacy(array $artistIds, bool $forceUpdate = false, ?int $batchSize = null, ?string $queueName = null): int
    {
        return $this->syncArtists($artistIds, $forceUpdate, $batchSize, $queueName, null, 'legacy');
    }
}