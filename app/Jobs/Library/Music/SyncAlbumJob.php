<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Jobs\Library\Music\Concerns\UpdatesAlbumMetadata;
use App\Models\Album;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\MetadataSyncService;
use App\Modules\Metadata\Search\AlbumSearchService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Psr\Log\LoggerInterface;

class SyncAlbumJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UpdatesAlbumMetadata;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly int   $albumId,
        private readonly bool  $forceUpdate = false,
        private readonly array $sources = ['general'],
        private readonly bool  $cascade = true,
    )
    {
    }

    public function handle(): void
    {
        $album = Album::find($this->albumId);

        if (!$album) {
            $this->getLogger()->warning('Album not found for sync', ['album_id' => $this->albumId]);
            return;
        }

        // Skip sync for unknown albums
        if ($album->shouldSkipMetadataLookup()) {
            $this->getLogger()->debug('Skipping metadata sync for unknown album', [
                'album_id' => $album->id,
                'title' => $album->attributes['title'] ?? 'N/A',
            ]);
            return;
        }

        $this->getLogger()->info('Starting album sync', [
            'album_id'           => $album->id,
            'title'              => $album->title,
            'artists'            => $album->artists->pluck('name')->toArray(),
            'sources'            => $this->sources,
            'force_update'       => $this->forceUpdate,
            'cascade'            => $this->cascade,
        ]);

        try {
            $this->handleModernSync($album);
        } catch (Exception $e) {
            $this->getLogger()->error('Album sync failed', [
                'album_id' => $this->albumId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function handleModernSync(Album $album): void
    {
        $syncResults = [];
        $identifiersUpdated = false;

        foreach ($this->sources as $source) {
            $result = $this->syncFromSource($album, $source);
            $syncResults[$source] = $result;

            if ($result['identifiers_updated'] ?? false) {
                $identifiersUpdated = true;
            }
        }

        if ($identifiersUpdated && $this->cascade) {
            $this->scheduleIdentifierBasedSync($album);
        }

        $this->getLogger()->info('Album sync completed', [
            'album_id'           => $album->id,
            'sync_results'       => $syncResults,
            'identifiers_updated' => $identifiersUpdated,
        ]);
    }

    private function syncFromSource(Album $album, string $source): array
    {
        try {
            $data = $this->fetchDataFromSource($album, $source);

            if (empty($data)) {
                return ['success' => false, 'reason' => 'no_data'];
            }

            $updatedFields = $this->updateAlbumMetadata($album, $data, $source);
            $identifiersUpdated = $this->hasIdentifierFields($updatedFields);

            return [
                'success'             => true,
                'updated_fields'      => array_keys($updatedFields),
                'identifiers_updated' => $identifiersUpdated,
            ];

        } catch (Exception $e) {
            $this->getLogger()->warning("Sync from $source failed", [
                'album_id' => $album->id,
                'source'   => $source,
                'error'    => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function fetchDataFromSource(Album $album, string $source): ?array
    {
        return match ($source) {
            'musicbrainz' => $this->fetchFromMusicBrainz($album),
            'discogs' => $this->fetchFromDiscogs($album),
            'general' => $this->fetchFromGeneral($album),
            default => null
        };
    }

    private function fetchFromMusicBrainz(Album $album): ?array
    {
        $searchService = app(AlbumSearchService::class);
        $result = $searchService->searchMusicBrainz($album);

        if (!$result || ($result['quality_score'] ?? 0) < 0.6) {
            return null;
        }

        return $result['data'] ?? null;
    }

    private function fetchFromDiscogs(Album $album): ?array
    {
        $searchService = app(AlbumSearchService::class);
        $result = $searchService->searchDiscogs($album);

        if (!$result || ($result['quality_score'] ?? 0) < 0.5) {
            return null;
        }

        return $result['data'] ?? null;
    }

    private function fetchFromGeneral(Album $album): ?array
    {
        $searchService = app(AlbumSearchService::class);
        $results = $searchService->searchAllSources($album);

        $bestResult = null;
        $bestScore = 0;

        foreach ($results as $result) {
            if ($result && ($result['quality_score'] ?? 0) > $bestScore) {
                $bestResult = $result;
                $bestScore = $result['quality_score'];
            }
        }

        if (!$bestResult) {
            return null;
        }

        // Check if the result has identifiers (mbid or discogs_id)
        $hasIdentifiers = !empty($bestResult['data']['mbid']) ||
            !empty($bestResult['data']['discogs_id']) ||
            !empty($bestResult['best_match']['id']);

        // Use lower threshold for results with identifiers
        $qualityThreshold = $hasIdentifiers ? 0.3 : 0.5;

        $this->getLogger()->debug('General search result evaluation', [
            'album_id' => $album->id,
            'best_score' => $bestScore,
            'has_identifiers' => $hasIdentifiers,
            'quality_threshold' => $qualityThreshold,
            'source' => $bestResult['source'] ?? 'unknown',
        ]);

        if ($bestScore < $qualityThreshold) {
            $this->getLogger()->debug('Result rejected due to low quality score', [
                'album_id' => $album->id,
                'score' => $bestScore,
                'threshold' => $qualityThreshold,
            ]);
            return null;
        }

        $data = $bestResult['data'];

        // Add identifier from best_match if available
        if ($bestResult['source'] === 'musicbrainz' && !empty($bestResult['best_match']['id'])) {
            $data['mbid'] = $bestResult['best_match']['id'];
        } else if ($bestResult['source'] === 'discogs' && !empty($bestResult['best_match']['id'])) {
            $data['discogs_id'] = $bestResult['best_match']['id'];
        }

        return $data;
    }

    private function extractYear($date): ?int
    {
        if (empty($date)) {
            return null;
        }

        if (is_string($date) && preg_match('/^(\d{4})/', $date, $matches)) {
            return (int)$matches[1];
        }

        return is_numeric($date) && $date > 0 ? (int)$date : null;
    }

    private function hasIdentifierFields(array $fields): bool
    {
        return !empty(array_intersect(['mbid', 'discogs_id'], $fields));
    }

    private function scheduleIdentifierBasedSync(Album $album): void
    {
        $sources = [];

        if ($album->mbid) {
            $sources[] = 'musicbrainz';
        }

        if ($album->discogs_id) {
            $sources[] = 'discogs';
        }

        if (!empty($sources)) {
            $this->getLogger()->info('Scheduling identifier-based sync', [
                'album_id' => $album->id,
                'sources'  => $sources,
            ]);

            static::dispatch($album->id, $this->forceUpdate, $sources, false)
                ->delay(now()->addMinutes(2));
        }
    }

    // Factory methods
    public static function syncGeneral(int $albumId, bool $forceUpdate = false): self
    {
        return new self($albumId, $forceUpdate, ['general'], true);
    }

    public static function syncFromMusicBrainz(int $albumId, bool $forceUpdate = false): self
    {
        return new self($albumId, $forceUpdate, ['musicbrainz'], false);
    }

    public static function syncFromDiscogs(int $albumId, bool $forceUpdate = false): self
    {
        return new self($albumId, $forceUpdate, ['discogs'], false);
    }

    public static function syncIdentifierBased(int $albumId, bool $forceUpdate = false): self
    {
        return new self($albumId, $forceUpdate, ['musicbrainz', 'discogs'], false);
    }

    public static function syncAll(int $albumId, bool $forceUpdate = false): self
    {
        return new self($albumId, $forceUpdate, ['general', 'musicbrainz', 'discogs'], false);
    }

    /**
     * Determine the number of times the job may be attempted.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 60, 180]; // 30 seconds, 1 minute, 3 minutes
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function timeout(): int
    {
        return 300; // 5 minutes
    }
}