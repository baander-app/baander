<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Album;
use App\Services\Metadata\MetadataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAlbumMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $albumId,
        private bool $forceUpdate = false
    ) {}

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $album = Album::find($this->albumId);

        if (!$album) {
            Log::channel('stdout')->warning('Album not found for metadata sync', ['album_id' => $this->albumId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncAlbum($album);

            if ($results['quality_score'] >= 0.7) {
                $this->updateAlbumMetadata($album, $results);

                Log::channel('stdout')->info('Album metadata synced successfully', [
                    'album_id' => $album->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);
            } else {
                Log::channel('stdout')->warning('Album metadata sync rejected due to low quality', [
                    'album_id' => $album->id,
                    'quality_score' => $results['quality_score']
                ]);
            }

        } catch (\Exception|\Error $e) {
            Log::channel('stdout')->error('Album metadata sync job failed', [
                'album_id' => $this->albumId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function updateAlbumMetadata(Album $album, array $results): void
    {
        // Update album if data is present and force update is enabled or current data is missing
        if ($results['album'] && ($this->forceUpdate || !$album->year)) {
            if ($results['album']['year']) {
                $album->update([
                    'year' => $results['album']['year']
                ]);
            }
        }

        // Update genres for songs
        if (!empty($results['genres'])) {
            $this->updateSongGenres($album, $results['genres']);
        }
    }

    private function updateSongGenres(Album $album, array $genreNames): void
    {
        $genres = collect($genreNames)->map(function ($genreName) {
            return \App\Models\Genre::firstOrCreate(['name' => $genreName]);
        });

        foreach ($album->songs as $song) {
            // Only add genres if song doesn't have any, or if force update is enabled
            if ($this->forceUpdate || $song->genres->isEmpty()) {
                $song->genres()->sync($genres->pluck('id'));
            }
        }
    }
}
