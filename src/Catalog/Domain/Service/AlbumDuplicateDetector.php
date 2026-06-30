<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\Uuid;

/**
 * Detects duplicate albums within a library using similarity scoring.
 */
final class AlbumDuplicateDetector
{
    private const TITLE_SIMILARITY_THRESHOLD = 0.85;
    private const ARTIST_OVERLAP_THRESHOLD = 0.5;

    public function __construct(
        private readonly AlbumRepositoryInterface $albumRepository,
        private readonly TitleNormalizer $titleNormalizer,
    ) {
    }

    /**
     * Finds duplicate album groups within a library.
     *
     * @return DuplicateGroup[]
     */
    public function findDuplicates(Uuid $libraryId): array
    {
        $albums = $this->albumRepository->findByLibrary($libraryId);

        if (count($albums) < 2) {
            return [];
        }

        // Build album data with normalized titles and artists
        $albumData = [];
        $albumIds = array_map(fn($album) => $album->getId(), $albums);

        $artistNamesMap = $this->albumRepository->getArtistNamesForAlbums($albumIds);

        foreach ($albums as $album) {
            $albumId = $album->getId()->toString();
            $artists = array_map(
                fn($artist) => $artist['name'],
                $artistNamesMap[$albumId] ?? [],
            );

            $albumData[$albumId] = [
                'album' => $album,
                'normalizedTitle' => $this->titleNormalizer->normalize($album->getTitle()),
                'artists' => array_unique(array_map('strtolower', $artists)),
                'year' => $album->getYear(),
            ];
        }

        $groups = [];
        $processed = [];

        foreach ($albumData as $id1 => $data1) {
            if (isset($processed[$id1])) {
                continue;
            }

            $matches = [$id1];

            foreach ($albumData as $id2 => $data2) {
                if ($id1 === $id2 || isset($processed[$id2])) {
                    continue;
                }

                if ($this->isDuplicate($data1, $data2)) {
                    $matches[] = $id2;
                    $processed[$id2] = true;
                }
            }

            if (count($matches) > 1) {
                $confidence = $this->calculateConfidence($data1, $matches, $albumData);
                $groups[] = new DuplicateGroup(
                    array_map(fn($id) => Uuid::fromString($id), $matches),
                    $confidence,
                );
            }

            $processed[$id1] = true;
        }

        return $groups;
    }

    /**
     * Determines if two albums are duplicates based on similarity rules.
     *
     * Rules:
     * 1. Same library (already filtered)
     * 2. Title similarity ≥ 85%
     * 3. Artist overlap ≥ 50%
     * 4. Year match (both null OR equal)
     */
    private function isDuplicate(array $a, array $b): bool
    {
        // Condition 2: Title similarity ≥ 85%
        $titleSimilarity = $this->calculateSimilarity(
            $a['normalizedTitle'],
            $b['normalizedTitle'],
        );
        if ($titleSimilarity < self::TITLE_SIMILARITY_THRESHOLD) {
            return false;
        }

        // Condition 3: Artist overlap ≥ 50%
        $artistOverlap = $this->calculateArtistOverlap($a['artists'], $b['artists']);
        if ($artistOverlap < self::ARTIST_OVERLAP_THRESHOLD) {
            return false;
        }

        // Condition 4: Year match
        if ($a['year'] !== null && $b['year'] !== null && $a['year'] !== $b['year']) {
            return false;
        }

        return true;
    }

    /**
     * Calculates Levenshtein-based string similarity.
     */
    private function calculateSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        $distance = levenshtein($a, $b);
        $maxLength = max(strlen($a), strlen($b));

        if ($maxLength === 0) {
            return 1.0;
        }

        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Calculates Jaccard index for artist overlap.
     */
    private function calculateArtistOverlap(array $artistsA, array $artistsB): float
    {
        if ($artistsA === [] && $artistsB === []) {
            return 0.0; // No artists means no overlap
        }

        $setA = array_flip($artistsA);
        $setB = array_flip($artistsB);

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count(array_unique(array_merge($artistsA, $artistsB)));

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Calculates confidence score for a duplicate group.
     *
     * Confidence is based on the average similarity scores within the group.
     */
    private function calculateConfidence(array $anchor, array $groupIds, array $albumData): float
    {
        if (count($groupIds) < 2) {
            return 1.0;
        }

        $totalSimilarity = 0.0;
        $comparisons = 0;

        foreach ($groupIds as $id) {
            if ($id === $anchor['album']->getId()->toString()) {
                continue;
            }

            $data = $albumData[$id];
            $titleSimilarity = $this->calculateSimilarity($anchor['normalizedTitle'], $data['normalizedTitle']);
            $artistOverlap = $this->calculateArtistOverlap($anchor['artists'], $data['artists']);

            // Weight title similarity higher than artist overlap
            $totalSimilarity += ($titleSimilarity * 0.7 + $artistOverlap * 0.3);
            $comparisons++;
        }

        if ($comparisons === 0) {
            return 1.0;
        }

        return $totalSimilarity / $comparisons;
    }
}
