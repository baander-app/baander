<?php

namespace App\Console\Commands;

use App\Jobs\Library\Music\SyncAlbumMetadataJob;
use App\Jobs\Library\Music\SyncArtistMetadataJob;
use App\Jobs\Library\Music\SyncSongMetadataJob;
use App\Models\Album;
use App\Models\Song;
use App\Models\Artist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class SyncMetadataCommand extends Command
{
    protected $signature = 'metadata:sync 
                           {--album=* : Specific album IDs to sync}
                           {--song=* : Specific song IDs to sync}
                           {--artist=* : Specific artist IDs to sync}
                           {--library= : Library ID to sync}
                           {--include-songs : Include songs when syncing albums}
                           {--include-artists : Include artists when syncing albums}
                           {--force : Force update existing metadata}
                           {--batch-size=10 : Number of items to process in each batch}
                           {--queue=default : Queue name for processing jobs}';

    protected $description = 'Sync metadata from external sources (MusicBrainz, Discogs) for albums, songs, and artists';

    public function handle(): int
    {
        $albumIds = $this->option('album');
        $songIds = $this->option('song');
        $artistIds = $this->option('artist');
        $libraryId = $this->option('library');
        $includeSongs = $this->option('include-songs');
        $includeArtists = $this->option('include-artists');
        $forceUpdate = $this->option('force');
        $batchSize = (int) $this->option('batch-size');
        $queueName = $this->option('queue');

        $totalJobs = 0;

        // Sync specific albums
        if (!empty($albumIds)) {
            $totalJobs += $this->syncAlbums($albumIds, $forceUpdate, $batchSize, $queueName, $includeSongs, $includeArtists);
        }

        // Sync specific songs
        if (!empty($songIds)) {
            $totalJobs += $this->syncSongs($songIds, $forceUpdate, $batchSize, $queueName);
        }

        // Sync specific artists
        if (!empty($artistIds)) {
            $totalJobs += $this->syncArtists($artistIds, $forceUpdate, $batchSize, $queueName);
        }

        // Sync entire library
        if ($libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            $totalJobs += $this->syncLibrary($libraryId, $forceUpdate, $batchSize, $queueName, $includeSongs, $includeArtists);
        }

        // If no specific options provided, show help
        if (!$libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            $this->error('Please specify what to sync: --album, --song, --artist, or --library');
            $this->line('Use --help for more information');
            return self::FAILURE;
        }

        if ($totalJobs > 0) {
            $this->info("Queued {$totalJobs} metadata sync jobs.");
        } else {
            $this->info('No items found to sync.');
        }

        return self::SUCCESS;
    }

    private function syncAlbums(array $albumIds, bool $forceUpdate, int $batchSize, string $queueName, bool $includeSongs = false, bool $includeArtists = false): int
    {
        $query = Album::query()->whereIn('id', $albumIds);
        $totalAlbums = $query->count();

        if ($totalAlbums === 0) {
            $this->warn('No albums found with the specified IDs.');
            return 0;
        }

        $this->info("Found {$totalAlbums} albums to sync.");

        $bar = $this->output->createProgressBar($totalAlbums);
        $bar->start();

        $jobCount = 0;

        $query->chunk($batchSize, function ($albums) use ($forceUpdate, $queueName, $bar, &$jobCount, $includeSongs, $includeArtists) {
            foreach ($albums as $album) {
                SyncAlbumMetadataJob::dispatch($album->id, $forceUpdate)
                    ->onQueue($queueName);
                $jobCount++;

                // Optionally sync songs in the album
                if ($includeSongs) {
                    foreach ($album->songs as $song) {
                        SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                            ->onQueue($queueName);
                        $jobCount++;
                    }
                }

                // Optionally sync artists in the album
                if ($includeArtists) {
                    foreach ($album->artists as $artist) {
                        SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                            ->onQueue($queueName);
                        $jobCount++;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return $jobCount;
    }

    private function syncSongs(array $songIds, bool $forceUpdate, int $batchSize, string $queueName): int
    {
        $query = Song::query()->whereIn('id', $songIds);
        $totalSongs = $query->count();

        if ($totalSongs === 0) {
            $this->warn('No songs found with the specified IDs.');
            return 0;
        }

        $this->info("Found {$totalSongs} songs to sync.");

        $bar = $this->output->createProgressBar($totalSongs);
        $bar->start();

        $jobCount = 0;

        $query->chunk($batchSize, function ($songs) use ($forceUpdate, $queueName, $bar, &$jobCount) {
            foreach ($songs as $song) {
                SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                    ->onQueue($queueName);
                $jobCount++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return $jobCount;
    }

    private function syncArtists(array $artistIds, bool $forceUpdate, int $batchSize, string $queueName): int
    {
        $query = Artist::query()->whereIn('id', $artistIds);
        $totalArtists = $query->count();

        if ($totalArtists === 0) {
            $this->warn('No artists found with the specified IDs.');
            return 0;
        }

        $this->info("Found {$totalArtists} artists to sync.");

        $bar = $this->output->createProgressBar($totalArtists);
        $bar->start();

        $jobCount = 0;

        $query->chunk($batchSize, function ($artists) use ($forceUpdate, $queueName, $bar, &$jobCount) {
            foreach ($artists as $artist) {
                SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                    ->onQueue($queueName);
                $jobCount++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return $jobCount;
    }

    private function syncLibrary(int $libraryId, bool $forceUpdate, int $batchSize, string $queueName, bool $includeSongs = false, bool $includeArtists = false): int
    {
        $this->info("Syncing metadata for library ID: {$libraryId}");

        $jobCount = 0;

        // Sync all albums in the library
        $albumQuery = Album::query()->where('library_id', $libraryId);
        $totalAlbums = $albumQuery->count();

        if ($totalAlbums > 0) {
            $this->info("Found {$totalAlbums} albums in library.");

            $bar = $this->output->createProgressBar($totalAlbums);
            $bar->setFormat('Albums: %current%/%max% [%bar%] %percent:3s%%');
            $bar->start();

            $albumQuery->chunk($batchSize, function ($albums) use ($forceUpdate, $queueName, $bar, &$jobCount, $includeSongs, $includeArtists) {
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

                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
        }

        // Optionally sync orphaned songs (songs without albums)
        if ($includeSongs) {
            $orphanedSongsQuery = Song::query()
                ->whereHas('album', function ($query) use ($libraryId) {
                    $query->where('library_id', $libraryId);
                })
                ->whereDoesntHave('album');

            $totalOrphanedSongs = $orphanedSongsQuery->count();

            if ($totalOrphanedSongs > 0) {
                $this->info("Found {$totalOrphanedSongs} orphaned songs in library.");

                $bar = $this->output->createProgressBar($totalOrphanedSongs);
                $bar->setFormat('Orphaned Songs: %current%/%max% [%bar%] %percent:3s%%');
                $bar->start();

                $orphanedSongsQuery->chunk($batchSize, function ($songs) use ($forceUpdate, $queueName, $bar, &$jobCount) {
                    foreach ($songs as $song) {
                        SyncSongMetadataJob::dispatch($song->id, $forceUpdate)
                            ->onQueue($queueName);
                        $jobCount++;
                        $bar->advance();
                    }
                });

                $bar->finish();
                $this->newLine();
            }
        }

        // Optionally sync all unique artists in the library
        if ($includeArtists) {
            $artistQuery = Artist::query()
                ->whereHas('albums', function ($query) use ($libraryId) {
                    $query->where('library_id', $libraryId);
                });

            $totalArtists = $artistQuery->count();

            if ($totalArtists > 0) {
                $this->info("Found {$totalArtists} unique artists in library.");

                $bar = $this->output->createProgressBar($totalArtists);
                $bar->setFormat('Artists: %current%/%max% [%bar%] %percent:3s%%');
                $bar->start();

                $artistQuery->chunk($batchSize, function ($artists) use ($forceUpdate, $queueName, $bar, &$jobCount) {
                    foreach ($artists as $artist) {
                        SyncArtistMetadataJob::dispatch($artist->id, $forceUpdate)
                            ->onQueue($queueName);
                        $jobCount++;
                        $bar->advance();
                    }
                });

                $bar->finish();
                $this->newLine();
            }
        }

        return $jobCount;
    }
}