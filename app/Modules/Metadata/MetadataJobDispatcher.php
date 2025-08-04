<?php

namespace App\Modules\Metadata;

use App\Jobs\Library\Music\{SyncAlbumMetadataJob, SyncArtistMetadataJob, SyncSongMetadataJob};
use App\Models\{Album, Artist, Song};
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
            &$jobCount,
            &$processed,
            $includeSongs,
            $includeArtists,
            $progressCallback,
            $totalAlbums,
        ) {
            foreach ($albums as $album) {
                SyncAlbumMetadataJob::dispatch($album->id, $forceUpdate)
                    ->onQueue($queueName);
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
                        SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                            ->onQueue($queueName);
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
            &$jobCount,
            &$processed,
            $progressCallback,
            $totalArtists,
        ) {
            foreach ($artists as $artist) {
                SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                    ->onQueue($queueName);
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
                &$totalJobs,
                &$processed,
                $includeSongs,
                $includeArtists,
                $progressCallback,
                $totalAlbums,
            ) {
                foreach ($albums as $album) {
                    SyncAlbumMetadataJob::dispatch($album->id, $forceUpdate)
                        ->onQueue($queueName);
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
                            SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                                ->onQueue($queueName);
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
                    &$totalJobs,
                    &$processed,
                    $progressCallback,
                    $totalArtists,
                ) {
                    foreach ($artists as $artist) {
                        SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                            ->onQueue($queueName);
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
}