<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use Psr\Log\LoggerInterface;

final class SongMetadataEnricher
{
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly DiscogsAdapter $discogs,
        private readonly SongPortInterface $songService,
        private readonly ArtistPortInterface $artistService,
        private readonly GenrePortInterface $genreService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enrich(Song $song, bool $forceUpdate = false): EnrichmentResult
    {
        $this->logger->info('Starting song enrichment', [
            'song_id' => $song->getId(),
            'title' => $song->getTitle(),
        ]);

        try {
            $data = $this->searchMusicBrainz($song);

            if ($data === null) {
                return EnrichmentResult::noMatch('musicbrainz', 0.0);
            }

            return $this->applyData($song, $data, $forceUpdate);
        } catch (\Throwable $e) {
            $this->logger->error('Song enrichment failed', [
                'song_id' => $song->getId(),
                'error' => $e->getMessage(),
            ]);

            return EnrichmentResult::failure('musicbrainz');
        }
    }

    private function searchMusicBrainz(Song $song): ?array
    {
        $result = $this->musicBrainz->searchRecording(
            $song->getTitle(),
            limit: 5,
        );

        if ($result->recordings !== []) {
            $best = $result->recordings[0];
            $quality = min(1.0, ($best->score ?? 0) / 100);

            return [
                'source' => 'musicbrainz',
                'quality' => $quality,
                'mbid' => $best->id ?? null,
                'title' => $best->title,
                'tags' => $best->tags,
            ];
        }

        return null;
    }

    private function applyData(Song $song, array $data, bool $forceUpdate): EnrichmentResult
    {
        $quality = $data['quality'] ?? 0.0;

        if ($quality < 0.6) {
            $this->logger->debug('Song enrichment rejected: quality below threshold', [
                'song_id' => $song->getId(),
                'quality' => $quality,
            ]);

            return EnrichmentResult::noMatch($data['source'], $quality);
        }

        $updatedFields = [];

        // Apply external identifiers
        if (!empty($data['mbid']) && ($forceUpdate || $song->getMbid() === null)) {
            $song->updateExternalIds(mbid: $data['mbid']);
            $updatedFields[] = 'mbid';
        }

        // Apply genre tags from external sources
        $genreNames = array_values(array_unique(array_filter($data['tags'] ?? [])));

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

        $updatedFields = array_values(array_unique($updatedFields));

        if (!empty($updatedFields)) {
            $this->songService->save($song);

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
            identifiersUpdated: !empty(array_intersect(['mbid', 'discogsId'], $updatedFields)),
        );
    }
}
