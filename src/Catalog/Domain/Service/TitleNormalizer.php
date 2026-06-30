<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use Transliterator;

/**
 * Normalizes titles for duplicate comparison by removing diacritics,
 * punctuation, and extra whitespace, and converting to lowercase.
 */
final class TitleNormalizer
{
    private const TransliteratorId = 'Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove;';

    private static ?Transliterator $transliterator = null;

    public function __construct()
    {
        if (self::$transliterator === null) {
            self::$transliterator = Transliterator::create(self::TransliteratorId);
        }
    }

    /**
     * Normalizes a title for comparison.
     *
     * Transformations:
     * - Remove MusicBrainz-style disambiguation brackets (e.g., "[Label, catalog, country]")
     * - Convert to lowercase
     * - Remove diacritics (é → e, ø → o)
     * - Remove punctuation
     * - Remove extra whitespace
     */
    public function normalize(string $title): string
    {
        // Remove MusicBrainz-style disambiguation: [Label, catalog, country]
        $normalized = preg_replace('/\s*\[[^]]*\]\s*$/', '', $title);

        // Transliterate to ASCII (removes diacritics)
        $normalized = self::$transliterator->transliterate($normalized);

        // Convert to lowercase
        $normalized = strtolower($normalized);

        // Remove punctuation and special characters, keep alphanumeric and spaces
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);

        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}
