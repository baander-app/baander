<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Jobs\Library\Music\Concerns\UpdatesArtistMetadata;
use App\Jobs\Middleware\MetadataRateLimiter;
use App\Models\Artist;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\Search\ArtistSearchService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Psr\Log\LoggerInterface;

class SyncArtistJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UpdatesArtistMetadata;

    public $timeout = 180;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly int   $artistId,
        private readonly bool  $forceUpdate = false,
        private readonly array $sources = ['general'],
        private readonly bool  $cascade = true,
    )
    {
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new MetadataRateLimiter(
                perSecond: config('scanner.music.rate_limiting.sync_jobs_per_second', 1)
            ),
        ];
    }

    public function handle(): void
    {
        $artist = Artist::find($this->artistId);

        if (!$artist) {
            $this->getLogger()->warning('Artist not found for sync', ['artist_id' => $this->artistId]);
            return;
        }

        $this->getLogger()->info('Starting artist sync', [
            'artist_id'    => $artist->id,
            'sources'      => $this->sources,
            'force_update' => $this->forceUpdate,
            'cascade'      => $this->cascade,
        ]);

        $syncResults = [];
        $identifiersUpdated = false;

        try {
            foreach ($this->sources as $source) {
                $result = $this->syncFromSource($artist, $source);
                $syncResults[$source] = $result;

                if ($result['identifiers_updated'] ?? false) {
                    $identifiersUpdated = true;
                }
            }

            // If identifiers were updated and cascade is enabled, schedule additional syncs
            if ($identifiersUpdated && $this->cascade) {
                $this->scheduleIdentifierBasedSync($artist);
            }

            $this->getLogger()->info('Artist sync completed', [
                'artist_id'           => $artist->id,
                'sync_results'        => $syncResults,
                'identifiers_updated' => $identifiersUpdated,
            ]);

        } catch (Exception $e) {
            $this->getLogger()->error('Artist sync failed', [
                'artist_id' => $this->artistId,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function syncFromSource(Artist $artist, string $source): array
    {
        try {
            $data = $this->fetchDataFromSource($artist, $source);

            if (empty($data)) {
                return ['success' => false, 'reason' => 'no_data'];
            }

            $updatedFields = $this->updateArtistMetadata($artist, $data, $source);
            $identifiersUpdated = $this->hasIdentifierFields($updatedFields);

            return [
                'success'             => true,
                'updated_fields'      => array_keys($updatedFields),
                'identifiers_updated' => $identifiersUpdated,
            ];

        } catch (Exception $e) {
            $this->getLogger()->warning("Sync from $source failed", [
                'artist_id' => $artist->id,
                'source'    => $source,
                'error'     => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function fetchDataFromSource(Artist $artist, string $source): ?array
    {
        return match ($source) {
            'musicbrainz' => $this->fetchFromMusicBrainz($artist),
            'discogs' => $this->fetchFromDiscogs($artist),
            'general' => $this->fetchFromGeneral($artist),
            default => null
        };
    }

    private function fetchFromMusicBrainz(Artist $artist): ?array
    {
        $searchService = app(ArtistSearchService::class);

        // If we already have an MBID, we can use it directly for lookup
        if ($artist->mbid) {
            // Use the search service's internal method by searching for a specific artist
            // This leverages the retry logic and error handling built into the service
            $result = $searchService->searchMusicBrainz($artist);
            return $result['data'] ?? null;
        }

        // If no MBID, search for the artist
        $result = $searchService->searchMusicBrainz($artist);

        if (!$result || ($result['quality_score'] ?? 0) < 0.7) {
            return null;
        }

        return $result['data'] ?? null;
    }

    private function fetchFromDiscogs(Artist $artist): ?array
    {
        $searchService = app(ArtistSearchService::class);

        // If we already have a Discogs ID, search will use it
        $result = $searchService->searchDiscogs($artist);

        if (!$result || ($result['quality_score'] ?? 0) < 0.6) {
            return null;
        }

        return $result['data'] ?? null;
    }

    private function fetchFromGeneral(Artist $artist): ?array
    {
        $searchService = app(ArtistSearchService::class);
        // Use searchAllSources for general metadata gathering
        $results = $searchService->searchAllSources($artist);

        $this->getLogger()->info('Search results', $results);

        // Find the best result from all sources
        $bestResult = null;
        $bestScore = 0;

        foreach ($results as $source => $result) {
            if ($result && ($result['quality_score'] ?? 0) > $bestScore) {
                $bestResult = $result;
                $bestScore = $result['quality_score'];
            }
        }

        // Use a lower threshold if we're getting valuable identifiers
        $hasIdentifiers = !empty($bestResult['data']['id']) || !empty($bestResult['data']['mbid']) || !empty($bestResult['data']['discogs_id']);
        $qualityThreshold = $hasIdentifiers ? 0.6 : 0.7;

        if (!$bestResult || $bestScore < $qualityThreshold) {
            return null;
        }

        // Convert the result to the expected format
        $data = $bestResult['data'];

        // Add identifiers based on source
        if ($bestResult['source'] === 'musicbrainz' && !empty($bestResult['best_match']['id'])) {
            $data['mbid'] = $bestResult['best_match']['id'];
        } else if ($bestResult['source'] === 'discogs' && !empty($bestResult['best_match']['id'])) {
            $data['discogs_id'] = $bestResult['best_match']['id'];
        }

        return $data;
    }

    protected function getFieldMappings(string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'name'                  => 'name',
                'sort-name'             => 'sort_name',
                'disambiguation'        => 'disambiguation',
                'type'                  => 'type',
                'gender'                => 'gender',
                'area.iso-3166-1-codes' => 'country',
                'mbid'                  => 'mbid',
            ],
            'discogs' => [
                'name'       => 'name',
                'realname'   => 'sort_name',
                'profile'    => 'disambiguation',
                'discogs_id' => 'discogs_id',
            ],
            'general' => [
                'name'           => 'name',
                'sort_name'      => 'sort_name',
                'disambiguation' => 'disambiguation',
                'type'           => 'type',
                'gender'         => 'gender',
                'country'        => 'country',
                'mbid'           => 'mbid',
                'discogs_id'     => 'discogs_id',
            ],
            default => []
        };
    }

    protected function processComplexFields(Artist $artist, array $data, string $source): array
    {
        $updateData = [];

        if ($source === 'musicbrainz' && isset($data['life-span'])) {
            $lifeSpan = $data['life-span'];

            if (!empty($lifeSpan['begin']) && $this->shouldUpdateField($artist, 'life_span_begin', $lifeSpan['begin'])) {
                $updateData['life_span_begin'] = $lifeSpan['begin'];
            }

            if (!empty($lifeSpan['end']) && $this->shouldUpdateField($artist, 'life_span_end', $lifeSpan['end'])) {
                $updateData['life_span_end'] = $lifeSpan['end'];
            }
        }

        if ($source === 'general' && isset($data['life_span'])) {
            $lifeSpan = $data['life_span'];

            if (!empty($lifeSpan['begin']) && $this->shouldUpdateField($artist, 'life_span_begin', $lifeSpan['begin'])) {
                $updateData['life_span_begin'] = $lifeSpan['begin'];
            }

            if (!empty($lifeSpan['end']) && $this->shouldUpdateField($artist, 'life_span_end', $lifeSpan['end'])) {
                $updateData['life_span_end'] = $lifeSpan['end'];
            }
        }

        return $updateData;
    }

    private function hasIdentifierFields(array $fields): bool
    {
        return !empty(array_intersect(['mbid', 'discogs_id'], $fields));
    }

    private function scheduleIdentifierBasedSync(Artist $artist): void
    {
        $sources = [];

        if ($artist->mbid) {
            $sources[] = 'musicbrainz';
        }

        if ($artist->discogs_id) {
            $sources[] = 'discogs';
        }

        if (!empty($sources)) {
            $this->getLogger()->info('Scheduling identifier-based sync', [
                'artist_id' => $artist->id,
                'sources'   => $sources,
            ]);

            static::dispatch($artist->id, false, $sources, false)
                ->delay(now()->addMinutes(2));
        }
    }

    public static function syncGeneral(int $artistId, bool $forceUpdate = false): self
    {
        return new self($artistId, $forceUpdate, ['general'], true);
    }

    public static function syncFromMusicBrainz(int $artistId, bool $forceUpdate = false): self
    {
        return new self($artistId, $forceUpdate, ['musicbrainz'], false);
    }

    public static function syncFromDiscogs(int $artistId, bool $forceUpdate = false): self
    {
        return new self($artistId, $forceUpdate, ['discogs'], false);
    }

    public static function syncIdentifierBased(int $artistId, bool $forceUpdate = false): self
    {
        return new self($artistId, $forceUpdate, ['musicbrainz', 'discogs'], false);
    }

    public static function syncAll(int $artistId, bool $forceUpdate = false): self
    {
        return new self($artistId, $forceUpdate, ['general', 'musicbrainz', 'discogs'], false);
    }
}