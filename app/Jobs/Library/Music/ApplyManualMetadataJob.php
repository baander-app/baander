<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Jobs\Library\Music\Concerns\UpdatesAlbumMetadata;
use App\Jobs\Middleware\MetadataRateLimiter;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Psr\Log\LoggerInterface;

class ApplyManualMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UpdatesAlbumMetadata;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly string $entityType,
        private readonly int    $entityId,
        private readonly string $source,
        private readonly string $externalId,
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
        $this->getLogger()->info('Starting manual metadata apply', [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'source' => $this->source,
            'external_id' => $this->externalId,
        ]);

        try {
            match ($this->entityType) {
                'album' => $this->applyToAlbum(),
                'artist' => $this->applyToArtist(),
                'song' => $this->applyToSong(),
                default => $this->getLogger()->warning('Unknown entity type for metadata apply', [
                    'entity_type' => $this->entityType,
                ]),
            };

            $this->getLogger()->info('Manual metadata apply completed', [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'source' => $this->source,
                'external_id' => $this->externalId,
            ]);

        } catch (Exception $e) {
            $this->getLogger()->error('Manual metadata apply failed', [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'source' => $this->source,
                'external_id' => $this->externalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Apply metadata to an album.
     */
    private function applyToAlbum(): void
    {
        $album = Album::find($this->entityId);

        if (!$album) {
            $this->getLogger()->warning('Album not found for metadata apply', [
                'album_id' => $this->entityId,
            ]);
            return;
        }

        $metadata = $this->fetchMetadataFromProvider('album');

        if (!$metadata) {
            $this->getLogger()->warning('Failed to fetch metadata from provider', [
                'album_id' => $this->entityId,
                'source' => $this->source,
                'external_id' => $this->externalId,
            ]);
            return;
        }

        $updatedFields = $this->updateAlbumMetadata($album, $metadata, $this->source);

        $this->getLogger()->info('Album metadata applied successfully', [
            'album_id' => $this->entityId,
            'updated_fields' => array_keys($updatedFields),
            'source' => $this->source,
        ]);
    }

    /**
     * Apply metadata to an artist.
     */
    private function applyToArtist(): void
    {
        $artist = Artist::find($this->entityId);

        if (!$artist) {
            $this->getLogger()->warning('Artist not found for metadata apply', [
                'artist_id' => $this->entityId,
            ]);
            return;
        }

        $metadata = $this->fetchMetadataFromProvider('artist');

        if (!$metadata) {
            $this->getLogger()->warning('Failed to fetch metadata from provider', [
                'artist_id' => $this->entityId,
                'source' => $this->source,
                'external_id' => $this->externalId,
            ]);
            return;
        }

        $updatedFields = $this->updateArtistMetadata($artist, $metadata, $this->source);

        $this->getLogger()->info('Artist metadata applied successfully', [
            'artist_id' => $this->entityId,
            'updated_fields' => array_keys($updatedFields),
            'source' => $this->source,
        ]);
    }

    /**
     * Apply metadata to a song.
     */
    private function applyToSong(): void
    {
        $song = Song::find($this->entityId);

        if (!$song) {
            $this->getLogger()->warning('Song not found for metadata apply', [
                'song_id' => $this->entityId,
            ]);
            return;
        }

        $metadata = $this->fetchMetadataFromProvider('song');

        if (!$metadata) {
            $this->getLogger()->warning('Failed to fetch metadata from provider', [
                'song_id' => $this->entityId,
                'source' => $this->source,
                'external_id' => $this->externalId,
            ]);
            return;
        }

        $updatedFields = $this->updateSongMetadata($song, $metadata, $this->source);

        $this->getLogger()->info('Song metadata applied successfully', [
            'song_id' => $this->entityId,
            'updated_fields' => array_keys($updatedFields),
            'source' => $this->source,
        ]);
    }

    /**
     * Fetch metadata from the provider.
     */
    private function fetchMetadataFromProvider(string $type): ?array
    {
        try {
            return match ($this->source) {
                'musicbrainz' => $this->fetchFromMusicBrainz($type),
                'discogs' => $this->fetchFromDiscogs($type),
                default => null,
            };
        } catch (Exception $e) {
            $this->getLogger()->error('Error fetching metadata from provider', [
                'source' => $this->source,
                'type' => $type,
                'external_id' => $this->externalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch from MusicBrainz.
     */
    private function fetchFromMusicBrainz(string $type): ?array
    {
        $client = app(\App\Http\Integrations\MusicBrainz\MusicBrainzClient::class);

        $data = match ($type) {
            'album' => $client->lookup->release($this->externalId),
            'artist' => $client->lookup->artist($this->externalId),
            'song' => $client->lookup->recording($this->externalId),
            default => null,
        };

        return $data?->toArray();
    }

    /**
     * Fetch from Discogs.
     */
    private function fetchFromDiscogs(string $type): ?array
    {
        $client = app(\App\Http\Integrations\Discogs\DiscogsClient::class);

        $data = match ($type) {
            'album' => $client->lookup->release($this->externalId),
            'artist' => $client->lookup->artist($this->externalId),
            'song' => null, // Discogs doesn't have separate song lookup
            default => null,
        };

        return $data?->toArray();
    }

    /**
     * Update artist metadata.
     */
    private function updateArtistMetadata(Artist $artist, array $data, string $source): array
    {
        $updateData = [];
        $fieldMappings = $this->getArtistFieldMappings($source);

        foreach ($fieldMappings as $sourceField => $artistField) {
            $value = data_get($data, $sourceField);

            if ($value && empty($artist->$artistField)) {
                $updateData[$artistField] = $value;
            }
        }

        if (!empty($updateData)) {
            $artist->update($updateData);

            $this->getLogger()->info('Artist metadata updated', [
                'artist_id' => $artist->id,
                'updated_fields' => array_keys($updateData),
                'source' => $source,
            ]);
        }

        return $updateData;
    }

    /**
     * Update song metadata.
     */
    private function updateSongMetadata(Song $song, array $data, string $source): array
    {
        $updateData = [];
        $fieldMappings = $this->getSongFieldMappings($source);

        foreach ($fieldMappings as $sourceField => $songField) {
            $value = data_get($data, $sourceField);

            if ($value && empty($song->$songField)) {
                $updateData[$songField] = $value;
            }
        }

        // Handle length conversion
        if (isset($data['length']) && empty($song->length)) {
            $updateData['length'] = (int) round($data['length'] / 1000); // Convert ms to seconds
        }

        if (!empty($updateData)) {
            $song->update($updateData);

            $this->getLogger()->info('Song metadata updated', [
                'song_id' => $song->id,
                'updated_fields' => array_keys($updateData),
                'source' => $source,
            ]);
        }

        return $updateData;
    }

    /**
     * Get artist field mappings for different sources.
     */
    private function getArtistFieldMappings(string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'name' => 'name',
                'id' => 'mbid',
                'country' => 'country',
                'disambiguation' => 'disambiguation',
                'type' => 'type',
                'gender' => 'gender',
            ],
            'discogs' => [
                'name' => 'name',
                'id' => 'discogs_id',
                'profile' => 'biography',
            ],
            default => [],
        };
    }

    /**
     * Get song field mappings for different sources.
     */
    private function getSongFieldMappings(string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'title' => 'title',
                'id' => 'mbid',
            ],
            'discogs' => [
                'title' => 'title',
                'duration' => 'length',
            ],
            default => [],
        };
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
        return [30, 60, 180];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function timeout(): int
    {
        return 300; // 5 minutes
    }
}
