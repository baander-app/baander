<?php

namespace App\Jobs\Library\Music;

use App\Models\Artist;
use App\Services\Metadata\MetadataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncArtistMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $artistId,
        private bool $forceUpdate = false
    ) {}

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $artist = Artist::find($this->artistId);

        if (!$artist) {
            Log::warning('Artist not found for metadata sync', ['artist_id' => $this->artistId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncArtist($artist);

            if ($results['quality_score'] >= 0.7) {
                $this->updateArtistMetadata($artist, $results);

                Log::info('Artist metadata synced successfully', [
                    'artist_id' => $artist->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);
            } else {
                Log::warning('Artist metadata sync rejected due to low quality', [
                    'artist_id' => $artist->id,
                    'quality_score' => $results['quality_score']
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Artist metadata sync job failed', [
                'artist_id' => $this->artistId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function updateArtistMetadata(Artist $artist, array $results): void
    {
        // Currently, artist metadata mainly consists of name and external IDs
        // Additional fields like bio, country, etc. could be added to the model later

        // Log successful sync for now - can be extended when more artist fields are added
        Log::info('Artist metadata processed', [
            'artist_id' => $artist->id,
            'albums_found' => count($results['albums'] ?? []),
            'source' => $results['source']
        ]);
    }
}
