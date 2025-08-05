
<?php

namespace App\Modules\Metadata;

use App\Models\{Album, Artist, Song};
use App\Jobs\Library\Music\{SyncAlbumJob, SyncArtistJob};
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\Processing\MetadataProcessor;
use App\Modules\Metadata\Search\{ArtistSearchService};
use App\Modules\Metadata\Search\AlbumSearchService;
use App\Modules\Metadata\Search\SongSearchService;
use Exception;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class MetadataSyncService
{
    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly AlbumSearchService   $albumSearchService,
        private readonly ArtistSearchService  $artistSearchService,
        private readonly SongSearchService    $songSearchService,
        private readonly MetadataProcessor    $metadataProcessor,
        private readonly LocalMetadataService $localMetadataService,
    )
    {
    }

    /**
     * Sync metadata for a specific song
     */
    public function syncSong(Song $song): array
    {
        try {
            $this->logger->debug('Starting metadata sync for song', [
                'song_id' => $song->id,
                'title'   => $song->title,
                'album'   => $song->album?->title,
            ]);

            $searchResults = $this->songSearchService->searchAllSources($song);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processSongMetadata($bestMatch, $song);

                $this->logger->debug('Song metadata processed successfully', [
                    'song_id'       => $song->id,
                    'source'        => $results['source'],
                    'quality_score' => $results['quality_score'],
                ]);

                return $results;
            }

            $this->logger->debug('No suitable match found for song', ['song_id' => $song->id]);
            return $this->getEmptySongResult();

        } catch (Exception $e) {
            $this->logger->error('Song metadata sync failed', [
                'song_id' => $song->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->getEmptySongResult();
        }
    }

    /**
     * Select the best match from multiple search results
     */
    private function selectBestMatch(array $searchResults): ?array
    {
        $validResults = array_filter($searchResults, static fn($result) => $result !== null);

        if (empty($validResults)) {
            return null;
        }

        if (count($validResults) === 1) {
            return reset($validResults);
        }

        // Return the result with the highest quality score
        return collect($validResults)
            ->sortByDesc('quality_score')
            ->first();
    }

    private function getEmptySongResult(): array
    {
        return [
            'song'          => null,
            'artists'       => [],
            'lyrics'        => null,
            'quality_score' => 0,
            'source'        => null,
        ];
    }

    /**
     * Batch sync entire album with all its songs
     */
    public function syncAlbumComplete(Album $album): array
    {
        $this->logger->info('Starting complete album sync', ['album_id' => $album->id]);

        // 1. Sync album metadata
        $albumResult = $this->syncAlbum($album);

        // 2. Sync artist metadata
        $artistResults = [];
        foreach ($album->artists as $artist) {
            $artistResults[$artist->id] = $this->syncArtist($artist);
        }

        // 3. Sync song metadata (with album context)
        $songResults = [];
        foreach ($album->songs as $song) {
            $songResults[$song->id] = $this->songSearchService->searchWithAlbumContext($song, $albumResult);
        }

        return [
            'album'   => $albumResult,
            'artists' => $artistResults,
            'songs'   => $songResults,
            'summary' => [
                'total_songs'        => count($songResults),
                'successful_songs'   => count(array_filter($songResults, static fn($r) => ($r['quality_score'] ?? 0) > 0)),
                'total_artists'      => count($artistResults),
                'successful_artists' => count(array_filter($artistResults, static fn($r) => ($r['quality_score'] ?? 0) > 0)),
                'album_quality'      => $albumResult['quality_score'] ?? 0,
            ],
        ];
    }

    /**
     * Sync metadata for a specific album
     *
     * @deprecated Use SyncAlbumJob directly for new implementations
     */
    public function syncAlbum(Album $album): array
    {
        try {
            $this->logger->debug('Starting metadata sync for album (legacy service)', [
                'album_id' => $album->id,
                'title'    => $album->title,
                'artists'  => $album->artists->pluck('name')->toArray(),
            ]);

            $searchResults = $this->albumSearchService->searchAllSources($album);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processAlbumMetadata($bestMatch, $album);

                $this->logger->debug('Album metadata processed successfully (legacy)', [
                    'album_id'      => $album->id,
                    'source'        => $results['source'],
                    'quality_score' => $results['quality_score'],
                ]);

                return $results;
            }

            // Fallback to local metadata analysis
            $this->logger->debug('No external match found, using local analysis', ['album_id' => $album->id]);
            return $this->localMetadataService->enhanceAlbumMetadata($album);

        } catch (Exception $e) {
            $this->logger->error('Album metadata sync failed (legacy)', [
                'album_id' => $album->id,
                'error'    => $e->getMessage(),
            ]);

            return $this->getEmptyAlbumResult();
        }
    }

    private function getEmptyAlbumResult(): array
    {
        return [
            'album'         => null,
            'artists'       => [],
            'songs'         => [],
            'quality_score' => 0,
            'source'        => null,
        ];
    }

    /**
     * Sync metadata for a specific artist
     *
     * @deprecated Use SyncArtistJob directly for new implementations
     */
    public function syncArtist(Artist $artist): array
    {
        try {
            $this->logger->debug('Starting metadata sync for artist (legacy service)', [
                'artist_id' => $artist->id,
                'name'      => $artist->name,
            ]);

            $searchResults = $this->artistSearchService->searchAllSources($artist);
            $bestMatch = $this->selectBestMatch($searchResults);

            if ($bestMatch) {
                $results = $this->metadataProcessor->processArtistMetadata($bestMatch, $artist);

                $this->logger->debug('Artist metadata processed successfully (legacy)', [
                    'artist_id'     => $artist->id,
                    'source'        => $results['source'],
                    'quality_score' => $results['quality_score'],
                ]);

                return $results;
            }

            $this->logger->debug('No suitable match found for artist (legacy)', ['artist_id' => $artist->id]);
            return $this->getEmptyArtistResult();

        } catch (Exception $e) {
            $this->logger->error('Artist metadata sync failed (legacy)', [
                'artist_id' => $artist->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->getEmptyArtistResult();
        }
    }

    private function getEmptyArtistResult(): array
    {
        return [
            'artist'        => null,
            'albums'        => [],
            'quality_score' => 0,
            'source'        => null,
        ];
    }

    /**
     * Async sync methods using the new job system
     */

    /**
     * Queue album sync using the modern job system
     */
    public function queueAlbumSync(
        Album  $album,
        bool   $forceUpdate = false,
        string $syncType = 'general',
        string $queue = 'default'
    ): void {
        $job = match ($syncType) {
            'general' => SyncAlbumJob::syncGeneral($album->id, $forceUpdate),
            'identifier' => SyncAlbumJob::syncIdentifierBased($album->id, $forceUpdate),
            'full' => SyncAlbumJob::syncAll($album->id, $forceUpdate),
            'musicbrainz' => SyncAlbumJob::syncFromMusicBrainz($album->id, $forceUpdate),
            'discogs' => SyncAlbumJob::syncFromDiscogs($album->id, $forceUpdate),
            'legacy' => SyncAlbumJob::syncLegacy($album->id, $forceUpdate),
            default => SyncAlbumJob::syncGeneral($album->id, $forceUpdate)
        };

        $job->onQueue($queue);
        dispatch($job);

        $this->logger->info('Album sync job queued', [
            'album_id'    => $album->id,
            'sync_type'   => $syncType,
            'force_update' => $forceUpdate,
            'queue'       => $queue,
        ]);
    }

    /**
     * Queue artist sync using the modern job system
     */
    public function queueArtistSync(
        Artist $artist,
        bool   $forceUpdate = false,
        string $syncType = 'general',
        string $queue = 'default'
    ): void {
        $job = match ($syncType) {
            'general' => SyncArtistJob::syncGeneral($artist->id, $forceUpdate),
            'identifier' => SyncArtistJob::syncIdentifierBased($artist->id, $forceUpdate),
            'full' => SyncArtistJob::syncAll($artist->id, $forceUpdate),
            'musicbrainz' => SyncArtistJob::syncFromMusicBrainz($artist->id, $forceUpdate),
            'discogs' => SyncArtistJob::syncFromDiscogs($artist->id, $forceUpdate),
            'legacy' => SyncArtistJob::syncLegacy($artist->id, $forceUpdate),
            default => SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
        };

        $job->onQueue($queue);
        dispatch($job);

        $this->logger->info('Artist sync job queued', [
            'artist_id'   => $artist->id,
            'sync_type'   => $syncType,
            'force_update' => $forceUpdate,
            'queue'       => $queue,
        ]);
    }

    /**
     * Queue complete album sync (album + artists + songs)
     */
    public function queueAlbumCompleteSync(
        Album  $album,
        bool   $forceUpdate = false,
        string $albumSyncType = 'general',
        string $artistSyncType = 'general',
        string $queue = 'default',
        int    $delayBetweenJobs = 30 // seconds
    ): array {
        $jobsQueued = [];

        // 1. Queue album sync
        $this->queueAlbumSync($album, $forceUpdate, $albumSyncType, $queue);
        $jobsQueued[] = ['type' => 'album', 'id' => $album->id, 'sync_type' => $albumSyncType];

        // 2. Queue artist sync for each album artist (with delay)
        $delay = now()->addSeconds($delayBetweenJobs);
        foreach ($album->artists as $artist) {
            $job = match ($artistSyncType) {
                'general' => SyncArtistJob::syncGeneral($artist->id, $forceUpdate),
                'identifier' => SyncArtistJob::syncIdentifierBased($artist->id, $forceUpdate),
                'full' => SyncArtistJob::syncAll($artist->id, $forceUpdate),
                'legacy' => SyncArtistJob::syncLegacy($artist->id, $forceUpdate),
                default => SyncArtistJob::syncGeneral($artist->id, $forceUpdate)
            };

            $job->onQueue($queue)->delay($delay);
            dispatch($job);

            $jobsQueued[] = ['type' => 'artist', 'id' => $artist->id, 'sync_type' => $artistSyncType];
            $delay = $delay->addSeconds($delayBetweenJobs);
        }

        // 3. Queue song sync (if song sync job exists)
        // Note: Assuming SyncSongMetadataJob exists and follows similar pattern
        $delay = $delay->addSeconds($delayBetweenJobs);
        foreach ($album->songs as $song) {
            // This would need to be implemented when SyncSongJob is created
            // For now, we'll skip this part or use the existing job if available
            $jobsQueued[] = ['type' => 'song', 'id' => $song->id, 'sync_type' => 'pending'];
        }

        $this->logger->info('Complete album sync jobs queued', [
            'album_id'          => $album->id,
            'jobs_queued'       => count($jobsQueued),
            'album_sync_type'   => $albumSyncType,
            'artist_sync_type'  => $artistSyncType,
            'delay_between_jobs' => $delayBetweenJobs,
        ]);

        return $jobsQueued;
    }

    /**
     * Batch queue multiple albums for sync
     */
    public function queueBatchAlbumSync(
        array  $albumIds,
        bool   $forceUpdate = false,
        string $syncType = 'general',
        string $queue = 'default',
        int    $batchSize = 10,
        int    $delayBetweenBatches = 60 // seconds
    ): int {
        $albums = Album::whereIn('id', $albumIds)->get();
        $totalJobs = 0;
        $delay = now();

        foreach ($albums->chunk($batchSize) as $batch) {
            foreach ($batch as $album) {
                $job = match ($syncType) {
                    'general' => SyncAlbumJob::syncGeneral($album->id, $forceUpdate),
                    'identifier' => SyncAlbumJob::syncIdentifierBased($album->id, $forceUpdate),
                    'full' => SyncAlbumJob::syncAll($album->id, $forceUpdate),
                    'legacy' => SyncAlbumJob::syncLegacy($album->id, $forceUpdate),
                    default => SyncAlbumJob::syncGeneral($album->id, $forceUpdate)
                };

                $job->onQueue($queue)->delay($delay);
                dispatch($job);
                $totalJobs++;
            }

            $delay = $delay->addSeconds($delayBetweenBatches);
        }

        $this->logger->info('Batch album sync jobs queued', [
            'total_albums' => count($albums),
            'total_jobs'   => $totalJobs,
            'sync_type'    => $syncType,
            'batch_size'   => $batchSize,
            'queue'        => $queue,
        ]);

        return $totalJobs;
    }

    /**
     * Get sync recommendations based on album metadata completeness
     */
    public function getSyncRecommendations(Album $album): array
    {
        $recommendations = [];

        // Check if album has identifiers
        $hasIdentifiers = !empty($album->mbid) || !empty($album->discogs_id);

        if ($hasIdentifiers) {
            $recommendations[] = [
                'type' => 'identifier_based',
                'reason' => 'Album has existing identifiers for enhanced metadata lookup',
                'priority' => 'high'
            ];
        }

        // Check completeness of basic metadata
        $missingFields = [];
        if (empty($album->year)) $missingFields[] = 'year';
        if (empty($album->country)) $missingFields[] = 'country';
        if (empty($album->label)) $missingFields[] = 'label';
        if (empty($album->catalog_number)) $missingFields[] = 'catalog_number';

        if (!empty($missingFields)) {
            $recommendations[] = [
                'type' => 'general',
                'reason' => 'Missing basic metadata: ' . implode(', ', $missingFields),
                'priority' => 'medium'
            ];
        }

        // Check if artists need sync
        $artistsNeedingSync = $album->artists->filter(function ($artist) {
            return empty($artist->mbid) && empty($artist->discogs_id);
        });

        if ($artistsNeedingSync->isNotEmpty()) {
            $recommendations[] = [
                'type' => 'with_artists',
                'reason' => 'Album artists missing identifiers',
                'priority' => 'medium'
            ];
        }

        // If no recommendations, suggest a light sync
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'general',
                'reason' => 'Routine metadata refresh',
                'priority' => 'low'
            ];
        }

        return $recommendations;
    }
}