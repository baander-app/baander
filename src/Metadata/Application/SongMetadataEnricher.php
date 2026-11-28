<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Application\Exception\MetadataProviderDisabled;
use Psr\Log\LoggerInterface;

/**
 * Fills a song's genres from the tags of the best MusicBrainz recording found for its title. It
 * writes nothing else: a song's recording ID comes only from a MusicBrainz match, which pairs the
 * song with a release track instead of trusting a title search.
 */
final class SongMetadataEnricher
{
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly SongPortInterface $songService,
        private readonly GenrePortInterface $genreService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** A forced enrichment fills genres the same way: genres are only ever added. */
    public function enrich(Song $song, bool $forceUpdate = false): EnrichmentResult
    {
        if (!$this->musicBrainz->isEnabled()) {
            return EnrichmentResult::disabled('musicbrainz');
        }
        $this->logger->info('Starting song enrichment', [
            'song_id' => $song->getId(),
            'title' => $song->getTitle(),
        ]);

        try {
            $data = $this->searchMusicBrainz($song);

            if ($data === null) {
                return EnrichmentResult::noMatch('musicbrainz', 0.0);
            }

            return $this->applyData($song, $data);
        } catch (MetadataProviderDisabled) {
            return EnrichmentResult::disabled('musicbrainz');
        } catch (\Throwable $e) {
            $this->logger->error('Song enrichment failed', [
                'song_id' => $song->getId(),
                'error' => $e->getMessage(),
            ]);

            return EnrichmentResult::failure('musicbrainz');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchMusicBrainz(Song $song): ?array
    {
        $result = $this->musicBrainz->searchRecording(
            $song->getTitle(),
            limit: 5,
        );

        if ($result->recordings !== []) {
            $best = $result->recordings[0];
            $quality = min(1.0, $best->score / 100);

            return [
                'source' => 'musicbrainz',
                'quality' => $quality,
                'title' => $best->title,
                'tags' => $best->tags,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyData(Song $song, array $data): EnrichmentResult
    {
        $quality = $data['quality'] ?? 0.0;

        if ($quality < 0.6) {
            $this->logger->debug('Song enrichment rejected: quality below threshold', [
                'song_id' => $song->getId(),
                'quality' => $quality,
            ]);

            return EnrichmentResult::noMatch($data['source'], $quality);
        }

        // The lookup takes seconds: decide and write against the stored state, so an edit or lock
        // made meanwhile survives.
        $current = $this->songService->findFreshByUuid($song->getId());

        if ($current === null) {
            $this->logger->info('Song deleted during enrichment', ['song_id' => $song->getId()]);

            return EnrichmentResult::failure($data['source'], $quality);
        }

        $song = $current;

        $updatedFields = [];

        // Apply genre tags from external sources
        $genreNames = array_filter($data['tags'] ?? []) |> array_unique(...) |> array_values(...);

        foreach ($genreNames as $genreName) {
            try {
                $genre = $this->genreService->findOrCreateByName($genreName);
                $this->genreService->addSongToGenre($genre->getId(), $song->getId());
                $updatedFields[] = 'genres';
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to assign genre to song', [
                    'song_id' => $song->getId(),
                    'genre' => $genreName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $updatedFields = array_unique($updatedFields) |> array_values(...);

        // The genre links are written on their own; the song row stays as it is.
        if ($updatedFields !== []) {
            $this->logger->info('Song enriched', [
                'song_id' => $song->getId(),
                'source' => $data['source'],
                'quality' => $quality,
                'fields' => $updatedFields,
            ]);
        }

        return new EnrichmentResult(
            success: true,
            source: $data['source'],
            qualityScore: $quality,
            updatedFields: $updatedFields,
        );
    }
}
