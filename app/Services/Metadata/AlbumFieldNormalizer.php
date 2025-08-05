<?php

namespace App\Services\Metadata;

class AlbumFieldNormalizer
{
    public static function normalize(string $field, $value, string $source): mixed
    {
        if (empty($value)) {
            return null;
        }

        return match ($field) {
            'year' => self::normalizeYear($value),
            'title' => self::normalizeTitle($value, $source),
            'mbid', 'discogs_id' => self::normalizeId($value),
            default => $value
        };
    }

    private static function normalizeYear($value): ?int
    {
        if (empty($value)) {
            return null;
        }

        // Handle various year formats
        // Extract year from date strings like "2023-01-01"
        if (is_string($value) && preg_match('/^(\d{4})/', $value, $matches)) {
            return (int)$matches[1];
        }

        return is_numeric($value) && $value > 0 ? (int)$value : null;
    }

    private static function normalizeTitle($value, string $source): ?string
    {
        if (empty($value)) {
            return null;
        }

        $title = trim($value);

        return match ($source) {
            'musicbrainz' => self::normalizeMusicBrainzTitle($title),
            'discogs' => self::normalizeDiscogsTitle($title),
            default => $title
        };
    }

    private static function normalizeMusicBrainzTitle(string $title): string
    {
        // Remove common MusicBrainz artifacts
        $title = preg_replace('/\s*\[.*?\]\s*$/', '', $title); // Remove bracketed suffixes
        return trim($title);
    }

    private static function normalizeDiscogsTitle(string $title): string
    {
        // Remove common Discogs artifacts
        $title = preg_replace('/\s*\(\d+\)\s*$/', '', $title); // Remove year suffixes in parentheses
        return trim($title);
    }

    private static function normalizeId($value): ?string
    {
        return is_string($value) && !empty(trim($value)) ? trim($value) : null;
    }
}