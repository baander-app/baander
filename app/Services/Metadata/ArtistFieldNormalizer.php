<?php

namespace App\Services\Metadata;

class ArtistFieldNormalizer
{
    public static function normalize(string $field, $value, string $source): mixed
    {
        if (empty($value)) {
            return null;
        }

        return match ($field) {
            'life_span_begin', 'life_span_end' => self::normalizeDate($value),
            'type', 'gender' => self::normalizeProviderSpecific($field, $value, $source),
            'country' => self::normalizeCountry($value, $source),
            'disambiguation' => self::normalizeDisambiguation($value, $source),
            default => $value
        };
    }

    private static function normalizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Handle year-only format (e.g., "1972")
        if (preg_match('/^\d{4}$/', $value)) {
            return $value . '-01-01';
        }

        // Handle year-month format (e.g., "1972-05")
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $value . '-01';
        }

        return $value;
    }

    private static function normalizeProviderSpecific(string $field, $value, string $source): mixed
    {
        return match ([$field, $source]) {
            ['type', 'musicbrainz'], ['gender', 'musicbrainz'] => strtolower($value),
            default => $value
        };
    }

    private static function normalizeCountry($value, string $source): ?string
    {
        return match ($source) {
            'musicbrainz' => is_array($value) ? ($value[0] ?? null) : $value,
            default => $value
        };
    }

    private static function normalizeDisambiguation($value, string $source): ?string
    {
        return match ($source) {
            'discogs' => self::extractDisambiguationFromProfile($value),
            default => $value
        };
    }

    private static function extractDisambiguationFromProfile(string $profile): string
    {
        $firstSentence = strtok($profile, '.');
        return strlen($firstSentence) < 200 ? trim($firstSentence) : '';
    }
}