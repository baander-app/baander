<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\MetadataSyncService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SyncSongMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    #[LogChannel(
        channel: Channel::Metadata,
        defaultContext: ['job_type' => 'metadata_sync']
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly int  $songId,
        private readonly bool $forceUpdate = false,
    )
    {
    }

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $song = (new \App\Models\Song)->find($this->songId);

        if (!$song) {
            $this->getLogger()->warning('Song not found for metadata sync', ['song_id' => $this->songId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncSong($song);

            if ($results['quality_score'] >= 0.6) {
                $this->updateSongMetadata($song, $results);

                $this->getLogger()->info('Song metadata synced successfully', [
                    'song_id'       => $song->id,
                    'mbid'          => $song->mbid,
                    'discogs_id'    => $song->discogs_id,
                    'source'        => $results['source'],
                    'quality_score' => $results['quality_score'],
                ]);
            } else {
                $this->getLogger()->warning('Song metadata sync rejected due to low quality', [
                    'song_id'       => $song->id,
                    'quality_score' => $results['quality_score'],
                ]);
            }

        } catch (Exception $e) {
            $this->getLogger()->error('Song metadata sync job failed', [
                'song_id' => $this->songId,
                'error'   => $e->getMessage(),
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

            // Update MusicBrainz ID if available and song doesn't have it
            if (isset($results['song']['mbid']) && $results['song']['mbid']) {
                if (!$song->mbid) {
                    $updateData['mbid'] = $results['song']['mbid'];
                } elseif ($this->forceUpdate) {
                    $updateData['mbid'] = $results['song']['mbid'];
                }
            }

            // Update Discogs ID if available and song doesn't have it
            if (isset($results['song']['discogs_id']) && $results['song']['discogs_id']) {
                if (!$song->discogs_id) {
                    $updateData['discogs_id'] = $results['song']['discogs_id'];
                } elseif ($this->forceUpdate) {
                    $updateData['discogs_id'] = $results['song']['discogs_id'];
                }
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
            return (new \App\Models\Genre)->firstOrCreate(['name' => $genreName]);
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
            return (new \App\Models\Artist)->firstOrCreate(['name' => $artistData['name']]);
        });

        $song->artists()->sync($artists->pluck('id'));
    }
}