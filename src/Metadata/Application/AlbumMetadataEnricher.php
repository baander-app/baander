<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use Psr\Log\LoggerInterface;

final class AlbumMetadataEnricher
{
    public function __construct(
        private readonly MusicBrainzAdapter $musicBrainz,
        private readonly DiscogsAdapter $discogs,
        private readonly AlbumPortInterface $albumService,
        private readonly GenrePortInterface $genreService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enrich(Album $album, bool $forceUpdate = false): EnrichmentResult
    {
        $this->logger->info('Starting album enrichment', [
            'album_id' => $album->getId(),
            'title' => $album->getTitle(),
        ]);

        try {
            $data = $this->searchGeneral($album);

            if ($data === null) {
                return EnrichmentResult::noMatch('general', 0.0);
            }

            // MBID-based target switching: if MusicBrainz data includes MBID,
            // check if an album with that MBID already exists in the same library
            if (!empty($data['mbid'])) {
                $mbid = MusicbrainzId::fromString($data['mbid']);
                $existingByMbid = $this->albumService->findByMbidAndLibrary(
                    $mbid,
                    $album->getLibraryId(),
                );

                if ($existingByMbid !== null
                    && $existingByMbid->getId()->toString() !== $album->getId()->toString()
                ) {
                    $this->logger->info('Switching enrichment target to existing album by MBID', [
                        'original_album_id' => $album->getId(),
                        'target_album_id' => $existingByMbid->getId(),
                        'mbid' => $data['mbid'],
                    ]);

                    $album = $existingByMbid;
                }
            }

            return $this->applyData($album, $data, 'general', $forceUpdate);
        } catch (\Throwable $e) {
            $this->logger->error('Album enrichment failed', [
                'album_id' => $album->getId(),
                'error' => $e->getMessage(),
            ]);

            return EnrichmentResult::failure('general');
        }
    }

    private function searchGeneral(Album $album): ?array
    {
        // Try MusicBrainz first (has better structured data)
        $mbResult = $this->musicBrainz->searchReleaseGroup(
            $album->getTitle(),
            limit: 5,
        );

        if ($mbResult->releaseGroups !== []) {
            $best = $mbResult->releaseGroups[0];
            $quality = min(1.0, ($best->score ?? 0) / 100);

            return [
                'source' => 'musicbrainz',
                'quality' => $quality,
                'mbid' => $best->id,
                'title' => $best->title,
                'primaryType' => $best->primaryType ?? null,
                'year' => $best->firstReleaseDate ?? null,
                'country' => null,
                'tags' => $best->tags,
                'genres' => [],
            ];
        }

        // Fallback to Discogs
        $discogsResult = $this->discogs->searchRelease($album->getTitle());

        if ($discogsResult->releases !== []) {
            $best = $discogsResult->releases[0];
            $quality = min(1.0, ($best->score ?? 0) / 100);

            return [
                'source' => 'discogs',
                'quality' => $quality,
                'discogsId' => (string) $best->id,
                'title' => $best->title,
                'year' => $best->year,
                'country' => null,
                'tags' => [],
                'genres' => $best->genres,
            ];
        }

        return null;
    }

    private function applyData(Album $album, array $data, string $source, bool $forceUpdate): EnrichmentResult
    {
        $quality = $data['quality'] ?? 0.0;
        $hasIdentifiers = !empty($data['mbid']) || !empty($data['discogsId']);
        $threshold = $hasIdentifiers ? 0.3 : 0.5;

        if ($quality < $threshold) {
            $this->logger->debug('Album enrichment rejected: quality below threshold', [
                'album_id' => $album->getId(),
                'quality' => $quality,
                'threshold' => $threshold,
            ]);

            return EnrichmentResult::noMatch($source, $quality);
        }

        $updatedFields = [];

        // Normalize title to extract disambiguation data from MusicBrainz
        $title = $data['title'] ?? null;
        $extractedData = [];

        if ($title !== null && $source === 'musicbrainz') {
            [$title, $extractedData] = $this->normalizeTitleWithDisambiguation($title);

            // Apply extracted disambiguation data to fields
            if (isset($extractedData['label']) && ($forceUpdate || $album->getLabel() === null)) {
                $album->updateMetadata(label: $extractedData['label']);
                $updatedFields[] = 'label';
            }
            if (isset($extractedData['catalogNumber']) && ($forceUpdate || $album->getCatalogNumber() === null)) {
                $album->updateMetadata(catalogNumber: $extractedData['catalogNumber']);
                $updatedFields[] = 'catalogNumber';
            }
            if (isset($extractedData['country']) && ($forceUpdate || $album->getCountry() === null)) {
                $album->updateMetadata(country: $extractedData['country']);
                $updatedFields[] = 'country';
            }
        }

        // Apply title only on force update or when empty-ish
        if ($title !== null && ($forceUpdate || !$hasIdentifiers)) {
            // Only update if the normalized title differs from current
            if ($forceUpdate || strlen($title) >= strlen($album->getTitle())) {
                if ($title !== $album->getTitle()) {
                    $album->updateMetadata(title: $title);
                    $updatedFields[] = 'title';
                }
            }
        }

        // Apply year
        if ($data['year'] !== null) {
            $year = $this->parseYear($data['year']);
            if ($year !== null && ($forceUpdate || $album->getYear() === null)) {
                $album->updateMetadata(year: $year);
                $updatedFields[] = 'year';
            }
        }

        // Apply country (if not already extracted from title normalization)
        if (!isset($extractedData['country']) && $data['country'] !== null && ($forceUpdate || $album->getCountry() === null)) {
            $album->updateMetadata(country: $data['country']);
            $updatedFields[] = 'country';
        }

        // Apply identifiers
        if (!empty($data['mbid']) && ($forceUpdate || $album->getMbid() === null)) {
            $album->updateExternalIds(mbid: $data['mbid']);
            $updatedFields[] = 'mbid';
        }

        if (!empty($data['discogsId']) && ($forceUpdate || $album->getDiscogsId() === null)) {
            $album->updateExternalIds(discogsId: $data['discogsId']);
            $updatedFields[] = 'discogsId';
        }

        // Apply genre tags from external sources
        $genreNames = array_values(array_unique(array_filter([
            ...($data['tags'] ?? []),
            ...($data['genres'] ?? []),
        ])));

        foreach ($genreNames as $genreName) {
            try {
                $genre = $this->genreService->findOrCreateByName($genreName);
                $this->genreService->addAlbumToGenre($genre->getId(), $album->getId());
                $updatedFields[] = 'genres';
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to assign genre to album', [
                    'album_id' => $album->getId(),
                    'genre' => $genreName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($updatedFields)) {
            $this->albumService->save($album);

            $this->logger->info('Album enriched', [
                'album_id' => $album->getId(),
                'source' => $source,
                'quality' => $quality,
                'fields' => array_values(array_unique($updatedFields)),
            ]);
        }

        return new EnrichmentResult(
            success: true,
            source: $source,
            qualityScore: $quality,
            updatedFields: $updatedFields,
            identifiersUpdated: !empty(array_intersect(['mbid', 'discogsId'], $updatedFields)),
        );
    }

    private function parseYear(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(\d{4})/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Normalizes a MusicBrainz title by extracting disambiguation data from suffix.
     *
     * Pattern: "Album Title [Label, Catalog#, Country]"
     * Extracts to: "Album Title" + {label, catalogNumber, country}
     *
     * @param string $mbTitle The title from MusicBrainz
     * @return array{0: string, 1: array<string, string>} [normalizedTitle, extractedData]
     */
    private function normalizeTitleWithDisambiguation(string $mbTitle): array
    {
        // Match pattern: "Title [Label, Catalog#, Country]"
        if (!preg_match('/^(.+?)\s*\[([^\]]+)\]\s*$/', $mbTitle, $matches)) {
            return [$mbTitle, []];
        }

        $normalizedTitle = rtrim($matches[1]);
        $suffixContents = $matches[2];

        // Split suffix by comma: "Label, Catalog#, Country"
        $suffixParts = array_map('trim', explode(',', $suffixContents));

        $extracted = [];
        if (isset($suffixParts[0]) && $suffixParts[0] !== '') {
            $extracted['label'] = $suffixParts[0];
        }
        if (isset($suffixParts[1]) && $suffixParts[1] !== '') {
            $extracted['catalogNumber'] = $suffixParts[1];
        }
        if (isset($suffixParts[2]) && $suffixParts[2] !== '') {
            $extracted['country'] = $suffixParts[2];
        }

        return [$normalizedTitle, $extracted];
    }
}
