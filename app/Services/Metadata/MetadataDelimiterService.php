<?php

namespace App\Services\Metadata;

use Illuminate\Support\Facades\Log;

/**
 * Smart delimiter detector for music metadata fields.
 *
 * Detects and splits artist, genre, and other multi-value fields
 * using intelligent pattern recognition.
 */
class MetadataDelimiterService
{
    /**
     * Default delimiter priority order for detection.
     */
    private const array DEFAULT_DELIMITER_PRIORITY = [
        ';',   // Semicolon - standard (MusicBrainz, Foobar, Jellyfin)
        '/',   // Forward slash - common
        '\\',  // Backslash - rare
        ',',   // Comma - ID3 standard (but conflicts with names)
        '&',   // Ampersand - informal
        ' vs ', // Versus - battle tracks
        ' feat.', // Featured - in titles
    ];

    /**
     * Patterns that suggest a specific delimiter is being used.
     */
    private const array DELIMITER_PATTERNS = [
        ';'      => '/[^;];[^;]/',           // "artist1;artist2"
        '/'      => '/[^\/]\/[^\/](?!$)/',    // "artist1/artist2" but not "AC/DC" at end
        '\\'     => '/[^\\\\]\\\\[^\\\\]/',  // "artist1\artist2"
        ','      => '/[A-Z][a-z]+,\s*[A-Z]/', // "Artist, Another" (capitalized words)
        '&'      => '/[A-Za-z]+&[A-Za-z]+/',   // "Artist&Another"
        ' vs '   => '/[A-Za-z]+ vs [A-Za-z]+/', // "Artist vs Artist"
        ' feat.' => '/[A-Za-z]+ feat\. [A-Za-z]+/', // "Artist feat. Artist"
    ];

    /**
     * Delimiters that should NOT be used (reasons).
     */
    private const array PROBLEMATIC_DELIMITERS = [
        '/' => ['AC/DC', 'M/F', 'R.E.M./Live'], // Artists with slashes in name
        ',' => ['Earth, Wind & Fire', 'The Beatles, The', 'Los Angeles, California'], // Could be locations
    ];

    /**
     * Detection options.
     */
    private array $options = [
        // Whether to use smart detection or fixed delimiters
        'smart_detection'         => true,

        // Fixed delimiters to use (if smart_detection is false)
        'artist_delimiters'       => [';'],

        // Delimiter priority for auto-detection
        'delimiter_priority'      => self::DEFAULT_DELIMITER_PRIORITY,

        // Minimum occurrences required to detect a delimiter
        'min_occurrences'         => 1,

        // Whether to fall back to trying all delimiters
        'fallback_all_delimiters' => true,
    ];

    /**
     * Create a new service instance with options.
     */
    public function __construct(array $options = [])
    {
        // Load known artist exceptions from config
        $configOptions = [
            'known_artist_exceptions' => config('scanner.music.delimiter_rules.known_artist_exceptions', []),
        ];

        $this->options = array_merge($this->options, $configOptions, $options);
    }

    /**
     * Split an artist field into multiple artists.
     *
     * @param string|array|null $artists The raw artist string or array
     * @return array<int, string> Array of artist names
     */
    public function splitArtists(string|array|null $artists): array
    {
        // If already an array from metadata, process each element
        if (is_array($artists)) {
            Log::channel('metadata')->debug('MetadataDelimiter: Artists already an array, processing each element', ['artists' => $artists]);

            $result = [];
            foreach ($artists as $artist) {
                $split = $this->splitArtists($artist);
                $result = array_merge($result, $split);
            }

            return $this->cleanAndFilter($result);
        }

        if (empty($artists)) {
            return [];
        }

        $trimmed = trim($artists);
        Log::channel('metadata')->debug('MetadataDelimiter: Splitting artists', ['input' => $trimmed]);

        // Check if it's a known exception (single artist with delimiter in name)
        if ($this->isKnownException($trimmed)) {
            Log::channel('metadata')->info('MetadataDelimiter: Known exception detected, not splitting', ['artist' => $trimmed]);
            return [$trimmed];
        }

        // Try smart detection first
        if ($this->options['smart_detection']) {
            $detected = $this->detectAndSplit($trimmed);

            if (!empty($detected)) {
                $result = $this->cleanAndFilter($detected);
                Log::channel('metadata')->info('MetadataDelimiter: Smart detection successful', [
                    'input' => $trimmed,
                    'result' => $result,
                ]);
                return $result;
            }

            Log::channel('metadata')->debug('MetadataDelimiter: Smart detection found no delimiters');
        }

        // Fallback to configured delimiters
        $result = $this->tryConfiguredDelimiters($trimmed);
        Log::channel('metadata')->info('MetadataDelimiter: Fallback delimiter result', [
            'input' => $trimmed,
            'result' => $result,
        ]);
        return $result;
    }

    /**
     * Split a genre field into multiple genres.
     *
     * @param string|array|null $genres The raw genre string or array
     * @return array<int, string> Array of genre names
     */
    public function splitGenres(string|array|null $genres): array
    {
        // If already an array from metadata, process each element
        if (is_array($genres)) {
            $result = [];
            foreach ($genres as $genre) {
                $split = $this->splitGenres($genre);
                $result = array_merge($result, $split);
            }

            return $this->cleanAndFilter($result);
        }

        if (empty($genres)) {
            return [];
        }

        // Genres typically use semicolon or slash
        $delimiters = $this->options['genre_delimiters'] ?? [';', '/'];

        foreach ($delimiters as $delimiter) {
            if (str_contains($genres, $delimiter)) {
                $split = explode($delimiter, $genres);
                return $this->cleanAndFilter($split);
            }
        }

        // Single genre
        return [$genres];
    }

    /**
     * Detect the most likely delimiter and split the string.
     */
    private function detectAndSplit(string $value): array
    {
        Log::channel('metadata')->debug('MetadataDelimiter: Starting smart detection', ['value' => $value]);

        foreach ($this->options['delimiter_priority'] as $delimiter) {
            Log::channel('metadata')->debug('MetadataDelimiter: Trying delimiter', ['delimiter' => $delimiter]);

            // Skip problematic delimiters if they match known exceptions
            if ($this->isProblematicDelimiter($delimiter, $value)) {
                Log::channel('metadata')->debug('MetadataDelimiter: Delimiter is problematic for this value, skipping');
                continue;
            }

            // Check if the delimiter pattern exists
            if (isset(self::DELIMITER_PATTERNS[$delimiter])) {
                $pattern = self::DELIMITER_PATTERNS[$delimiter];
                Log::channel('metadata')->debug('MetadataDelimiter: Checking pattern', ['pattern' => $pattern]);

                if (preg_match($pattern, $value)) {
                    $parts = explode($delimiter, $value);
                    Log::channel('metadata')->debug('MetadataDelimiter: Pattern matched', ['parts' => $parts, 'count' => count($parts)]);

                    // Verify we have multiple parts and minimum occurrences
                    if (count($parts) > 1 && substr_count($value, $delimiter) >= $this->options['min_occurrences']) {
                        Log::channel('metadata')->info('MetadataDelimiter: Split successful via pattern', [
                            'delimiter' => $delimiter,
                            'parts' => $parts,
                        ]);
                        return $parts;
                    }
                }
            }

            // Simple substring check as fallback
            if (str_contains($value, $delimiter)) {
                $parts = explode($delimiter, $value);
                $count = count($parts);
                Log::channel('metadata')->debug('MetadataDelimiter: Delimiter found via substring', [
                    'delimiter' => $delimiter,
                    'parts' => $parts,
                    'count' => $count,
                ]);

                if ($count > 1) {
                    Log::channel('metadata')->info('MetadataDelimiter: Split successful via substring', [
                        'delimiter' => $delimiter,
                        'parts' => $parts,
                    ]);
                    return $parts;
                }
            }
        }

        Log::channel('metadata')->debug('MetadataDelimiter: No delimiter found');
        return [];
    }

    /**
     * Try configured delimiters as fallback.
     */
    private function tryConfiguredDelimiters(string $value): array
    {
        $delimiters = $this->options['artist_delimiters'] ?? [';'];

        foreach ($delimiters as $delimiter) {
            if (str_contains($value, $delimiter)) {
                return explode($delimiter, $value);
            }
        }

        // If no delimiter found and fallback is enabled, try all
        if ($this->options['fallback_all_delimiters']) {
            foreach (self::DEFAULT_DELIMITER_PRIORITY as $delimiter) {
                if (str_contains($value, $delimiter) && count(explode($delimiter, $value)) > 1) {
                    return explode($delimiter, $value);
                }
            }
        }

        // Return as single value
        return [$value];
    }

    /**
     * Check if a value is a known exception (single artist with delimiter).
     */
    private function isKnownException(string $value): bool
    {
        return in_array($value, $this->options['known_artist_exceptions'], true);
    }

    /**
     * Check if a delimiter is problematic for this specific value.
     */
    private function isProblematicDelimiter(string $delimiter, string $value): bool
    {
        if (!isset(self::PROBLEMATIC_DELIMITERS[$delimiter])) {
            return false;
        }

        foreach (self::PROBLEMATIC_DELIMITERS[$delimiter] as $exception) {
            if (str_contains($value, $exception)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean and filter split values.
     */
    private function cleanAndFilter(array $values): array
    {
        return array_filter(
            array_map(fn($v) => trim($v), $values),
            fn($v) => !empty($v),
        );
    }

    /**
     * Get the current options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Update options.
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Add a known artist exception.
     */
    public function addArtistException(string $artist): self
    {
        $this->options['known_artist_exceptions'][] = $artist;

        return $this;
    }

    /**
     * Add custom delimiter priority.
     */
    public function setDelimiterPriority(array $delimiters): self
    {
        $this->options['delimiter_priority'] = $delimiters;

        return $this;
    }
}
