<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Song;
use App\Modules\Metadata\MetadataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSongMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $songId,
        private readonly bool $forceUpdate = false
    ) {}

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $song = Song::find($this->songId);

        if (!$song) {
            $this->logger()->warning('Song not found for metadata sync', ['song_id' => $this->songId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncSong($song);

            if ($results['quality_score'] >= 0.6) {
                $this->updateSongMetadata($song, $results);

                $this->logger()->info('Song metadata synced successfully', [
                    'song_id' => $song->id,
                    'source' => $results['source'],
                    'quality_score' => $results['quality_score']
                ]);
            } else {
                $this->logger()->warning('Song metadata sync rejected due to low quality', [
                    'song_id' => $song->id,
                    'quality_score' => $results['quality_score']
                ]);
            }

        } catch (\Exception $e) {
            $this->logger()->error('Song metadata sync job failed', [
                'song_id' => $this->songId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function updateSongMetadata(Song $song, array $results): void
    {
        $updateData = [];

        // Update song data if available and conditions are met
        if ($results['song']) {
            if (($this->forceUpdate || !$song->length) && isset($results['song']['length'])) {
                $updateData['length'] = $results['song']['length'];
            }
        }

        // Update the song if we have data to update
        if (!empty($updateData)) {
            $song->update($updateData);
        }

        // Update genres
        if (!empty($results['genres'])) {
            $this->updateSongGenres($song, $results['genres']);
        }

        // Update artists if available
        if (!empty($results['artists'])) {
            $this->updateSongArtists($song, $results['artists']);
        }
    }

    private function updateSongGenres(Song $song, array $genreNames): void
    {
        $genres = collect($genreNames)->map(function ($genreName) {
            return \App\Models\Genre::firstOrCreate(['name' => $genreName]);
        });

        // Only update if song doesn't have genres, or if force update is enabled
        if ($this->forceUpdate || $song->genres->isEmpty()) {
            $song->genres()->sync($genres->pluck('id'));
        }
    }

    private function updateSongArtists(Song $song, array $artistsData): void
    {
        // Only update if song doesn't have artists, or if force update is enabled
        if (!$this->forceUpdate && $song->artists->isNotEmpty()) {
            return;
        }

        $artists = collect($artistsData)->map(function ($artistData) {
            return \App\Models\Artist::firstOrCreate(['name' => $artistData['name']]);
        });

        $song->artists()->sync($artists->pluck('id'));
    }
}
