<?php

namespace App\Jobs\Library\Music;

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Jobs\BaseJob;
use App\Jobs\Library\Music\Concerns\UpdatesArtistMetadata;
use App\Models\Artist;
use App\Modules\Metadata\MetadataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class SyncArtistJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UpdatesArtistMetadata;

    public function __construct(
        private readonly int   $artistId,
        private readonly bool  $forceUpdate = false,
        private readonly array $sources = ['general'],
        private readonly bool  $cascade = true,
    )
    {
    }

    public function handle(): void
    {
        $artist = Artist::find($this->artistId);

        if (!$artist) {
            $this->logger()->warning('Artist not found for sync', ['artist_id' => $this->artistId]);
            return;
        }

        $this->logger()->info('Starting artist sync', [
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

            $this->logger()->info('Artist sync completed', [
                'artist_id'           => $artist->id,
                'sync_results'        => $syncResults,
                'identifiers_updated' => $identifiersUpdated,
            ]);

        } catch (\Exception $e) {
            $this->logger()->error('Artist sync failed', [
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

        } catch (\Exception $e) {
            $this->logger()->warning("Sync from $source failed", [
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
        if (!$artist->mbid) {
            return null;
        }

        $client = app(MusicBrainzClient::class);

        return $client->getArtist($artist->mbid);
    }

    private function fetchFromDiscogs(Artist $artist): ?array
    {
        if (!$artist->discogs_id) {
            return null;
        }

        $client = app(DiscogsClient::class);
        return $client->artist()->get($artist->discogs_id);
    }

    private function fetchFromGeneral(Artist $artist): ?array
    {
        $service = app(MetadataSyncService::class);
        $results = $service->syncArtist($artist);

        // Use lower threshold if we're getting valuable identifiers
        $hasIdentifiers = !empty($results['artist']['mbid']) || !empty($results['artist']['discogs_id']);
        $qualityThreshold = $hasIdentifiers ? 0.6 : 0.7;

        if ($results['quality_score'] < $qualityThreshold) {
            return null;
        }

        return $results['artist'] ?? null;
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
            ],
            'discogs' => [
                'name'     => 'name',
                'realname' => 'sort_name',
                'profile'  => 'disambiguation',
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
            $this->logger()->info('Scheduling identifier-based sync', [
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