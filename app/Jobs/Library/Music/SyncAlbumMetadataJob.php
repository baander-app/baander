<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Album;
use App\Models\Genre;
use App\Modules\Metadata\MetadataSyncService;
use Error;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAlbumMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int  $albumId,
        private readonly bool $forceUpdate = false,
    )
    {
    }

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $album = (new \App\Models\Album)->find($this->albumId);

        if (!$album) {
            $this->logger()->warning('Album not found for metadata sync', ['album_id' => $this->albumId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncAlbum($album);

            if ($results['quality_score'] >= 0.5) {
                $this->updateAlbumMetadata($album, $results);

                $this->logger()->info('Album metadata synced successfully', [
                    'album_id'      => $album->id,
                    'mbid'          => $album->mbid,
                    'discogs_id'    => $album->discogs_id,
                    'source'        => $results['source'],
                    'quality_score' => $results['quality_score'],
                ]);
            } else {
                // Also log what the album data looks like for debugging
                $this->logger()->warning('Album metadata sync rejected due to low quality', [
                    'album_id'      => $album->id,
                    'quality_score' => $results['quality_score'],
                    'source'        => $results['source'],
                    'album_title'   => $album->title,
                    'album_artists' => $album->artists->pluck('name')->toArray(),
                    'has_year'      => !empty($album->year),
                    'songs_count'   => $album->songs()->count(),
                ]);
            }

        } catch (Exception|Error $e) {
            $this->logger()->error('Album metadata sync job failed', [
                'album_id' => $this->albumId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }


    private function updateAlbumMetadata(Album $album, array $results): void
    {
        if ($results['album']) {
            $updateData = [];

            // Update year if available
            if (isset($results['album']['year']) && $results['album']['year']) {
                if ($this->forceUpdate || !$album->year) {
                    $updateData['year'] = $results['album']['year'];
                }
            }

            // Update title if available
            if (isset($results['album']['title']) && $results['album']['title']) {
                if ($this->forceUpdate || !$album->title) {
                    $updateData['title'] = $results['album']['title'];
                }
            }

            // Update MusicBrainz ID if available and album doesn't have it
            if (isset($results['album']['mbid']) && $results['album']['mbid']) {
                if (!$album->mbid) {
                    $updateData['mbid'] = $results['album']['mbid'];
                } elseif ($this->forceUpdate) {
                    $updateData['mbid'] = $results['album']['mbid'];
                }
            }

            // Update Discogs ID if available and album doesn't have it
            if (isset($results['album']['discogs_id']) && $results['album']['discogs_id']) {
                if (!$album->discogs_id) {
                    $updateData['discogs_id'] = $results['album']['discogs_id'];
                } elseif ($this->forceUpdate) {
                    $updateData['discogs_id'] = $results['album']['discogs_id'];
                }
            }

            // Only update if we have data to update
            if (!empty($updateData)) {
                $album->update($updateData);
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
            return (new \App\Models\Genre)->firstOrCreate(['name' => $genreName]);
        });

        foreach ($album->songs as $song) {
            // Only add genres if song doesn't have any, or if force update is enabled
            if ($this->forceUpdate || $song->genres->isEmpty()) {
                $song->genres()->sync($genres->pluck('id'));
            }
        }
    }
}