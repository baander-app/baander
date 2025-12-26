<?php

namespace App\Jobs;

use App\Jobs\Middleware\MetadataRateLimiter;
use App\Models\Genre;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class SyncMusicBrainzGenres extends BaseJob
{
    private const int BATCH_SIZE = 100;
    private const string GENRE_ALL_ENDPOINT = 'https://musicbrainz.org/ws/2/genre/all';

    public $timeout = 3600;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

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
        $this->getLogger()->info('Starting MusicBrainz genre sync');

        $offset = 0;
        $totalFetched = 0;
        $totalCreated = 0;
        $totalUpdated = 0;

        do {
            $this->getLogger()->info("Fetching genres with offset: {$offset}");

            $response = Http::get(self::GENRE_ALL_ENDPOINT, [
                'fmt' => 'json',
                'limit' => self::BATCH_SIZE,
                'offset' => $offset,
            ]);

            if (!$response->successful()) {
                $this->getLogger()->error('Failed to fetch genres from MusicBrainz', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception("MusicBrainz API request failed: {$response->status()}");
            }

            $data = $response->json();

            if (!isset($data['genres']) || empty($data['genres'])) {
                $this->getLogger()->info('No more genres found');
                break;
            }

            $genres = $data['genres'];
            $genreCount = count($genres);
            $totalFetched += $genreCount;

            $this->getLogger()->info("Fetched {$genreCount} genres", [
                'offset' => $offset,
                'total_fetched' => $totalFetched,
            ]);

            // Sync genres to database
            foreach ($genres as $mbGenre) {
                $genre = Genre::where('mbid', $mbGenre['id'])->first();

                if (!$genre) {
                    // Create new genre
                    $genre = Genre::create([
                        'name' => $mbGenre['name'],
                        'slug' => Str::slug($mbGenre['name']),
                        'mbid' => $mbGenre['id'],
                    ]);
                    $totalCreated++;
                    $this->getLogger()->debug("Created new genre", [
                        'name' => $genre->name,
                        'mbid' => $genre->mbid,
                    ]);
                } else {
                    // Update existing genre (name might have changed)
                    $genre->update([
                        'name' => $mbGenre['name'],
                        'slug' => Str::slug($mbGenre['name']),
                    ]);
                    $totalUpdated++;
                }
            }

            // Check if we should continue
            // MusicBrainz returns 'genre-offset' with the total count
            $totalCount = $data['genre-offset'] ?? null;

            if ($totalCount !== null && $totalCount > 0) {   // ← add the “> 0” check
                $this->getLogger()->info("Progress", [
                    'fetched' => $totalFetched,
                    'total'   => $totalCount,
                    'progress'=> round(($totalFetched / $totalCount) * 100, 2) . '%',
                ]);

                if ($offset + $genreCount >= $totalCount) {
                    $this->getLogger()->info('Fetched all available genres');
                }
            } else {
                $this->getLogger()->info("Progress", [
                    'fetched' => $totalFetched,
                    'total'   => $totalCount,
                    'progress'=> 'N/A',
                ]);
            }

            $offset += self::BATCH_SIZE;

            // Small delay to respect rate limits
            usleep(1000000); // 1 second

        } while (true);

        $this->getLogger()->info('MusicBrainz genre sync completed', [
            'total_fetched' => $totalFetched,
            'total_created' => $totalCreated,
            'total_updated' => $totalUpdated,
        ]);
    }
}
