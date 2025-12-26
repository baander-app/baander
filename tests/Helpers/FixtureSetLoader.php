<?php

namespace Tests\Helpers;

/**
 * Loads pre-defined fixture sets
 */
class FixtureSetLoader
{
    private string $fixturesPath;

    public function __construct()
    {
        $this->fixturesPath = base_path('tests/Fixtures/sets');
    }

    /**
     * Load a fixture set by name
     */
    public function load(
        string $set,
        LastFmFixtureBuilder $lastFm,
        DiscogsFixtureBuilder $discogs,
    ): void {
        $sets = $this->getAvailableSets();

        if (!isset($sets[$set])) {
            throw new \InvalidArgumentException("Unknown fixture set: {$set}. Available: " . implode(', ', array_keys($sets)));
        }

        $config = $sets[$set];

        // Add LastFM tags
        foreach ($config['lastfm']['tags'] ?? [] as $tag) {
            $lastFm->tag($tag['name'], reach: $tag['reach'] ?? 0, summary: $tag['summary'] ?? null);
        }

        // Add Discogs genres
        foreach ($config['discogs']['genres'] ?? [] as $genre => $styles) {
            $discogs->addRelease($genre, $styles);
        }
    }

    /**
     * Get available fixture sets
     *
     * In the future, these could be loaded from JSON files in tests/Fixtures/sets/
     */
    private function getAvailableSets(): array
    {
        return [
            'rock-family' => [
                'lastfm' => [
                    'tags' => [
                        ['name' => 'rock', 'reach' => 1_000_000, 'summary' => 'Rock music is a broad genre'],
                        ['name' => 'hard rock', 'reach' => 500_000, 'summary' => 'Hard rock is a subgenre'],
                        ['name' => 'punk rock', 'reach' => 450_000, 'summary' => 'Punk rock developed in the 1970s'],
                        ['name' => 'classic rock', 'reach' => 600_000, 'summary' => 'Classic rock hits'],
                    ],
                ],
                'discogs' => [
                    'genres' => [
                        'rock' => ['hard rock', 'classic rock', 'punk rock', 'alternative'],
                    ],
                ],
            ],
            'electronic-family' => [
                'lastfm' => [
                    'tags' => [
                        ['name' => 'electronic', 'reach' => 900_000, 'summary' => 'Electronic music'],
                        ['name' => 'techno', 'reach' => 400_000, 'summary' => 'Techno music'],
                        ['name' => 'house', 'reach' => 380_000, 'summary' => 'House music'],
                        ['name' => 'ambient', 'reach' => 300_000, 'summary' => 'Ambient music'],
                    ],
                ],
                'discogs' => [
                    'genres' => [
                        'electronic' => ['techno', 'house', 'ambient', 'trance', 'dubstep'],
                    ],
                ],
            ],
            'popular-genres' => [
                'lastfm' => [
                    'tags' => [
                        ['name' => 'rock', 'reach' => 1_000_000],
                        ['name' => 'pop', 'reach' => 950_000],
                        ['name' => 'hip hop', 'reach' => 850_000],
                        ['name' => 'jazz', 'reach' => 650_000],
                        ['name' => 'electronic', 'reach' => 900_000],
                    ],
                ],
                'discogs' => [
                    'genres' => [
                        'rock' => ['hard rock', 'classic rock'],
                        'electronic' => ['techno', 'house'],
                    ],
                ],
            ],
        ];
    }
}
