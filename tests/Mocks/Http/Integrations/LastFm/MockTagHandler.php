<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\TagHandler as BaseTagHandler;
use Exception;

class MockTagHandler extends BaseTagHandler
{
    private array $fixtures;
    private bool $simulateFailure = false;

    public function __construct(array $fixtures = [], bool $simulateFailure = false)
    {
        $this->fixtures = $fixtures;
        $this->simulateFailure = $simulateFailure;
        // Don't call parent constructor
    }

    public function getTagInfo(string $tag): array
    {
        if ($this->simulateFailure) {
            throw new Exception("Simulated API failure for tag: {$tag}");
        }

        $tagLower = strtolower(str_replace(' ', '-', $tag));
        $key = "tag_getInfo_{$tagLower}";

        if (isset($this->fixtures[$key])) {
            return $this->fixtures[$key]['tag'] ?? [];
        }

        // Return default response for unknown tags
        return [
            'name' => $tag,
            'reach' => rand(100000, 900000),
            'wiki' => [
                'summary' => "Mock summary for {$tag}",
            ],
        ];
    }

    public function getSimilarTags(string $tag): array
    {
        // Return mock similar tags based on the requested tag
        $similarMap = [
            'rock' => [
                ['name' => 'hard rock', 'reach' => 500000],
                ['name' => 'classic rock', 'reach' => 450000],
                ['name' => 'punk rock', 'reach' => 400000],
                ['name' => 'alternative rock', 'reach' => 350000],
            ],
            'electronic' => [
                ['name' => 'techno', 'reach' => 400000],
                ['name' => 'house', 'reach' => 350000],
                ['name' => 'ambient', 'reach' => 300000],
                ['name' => 'trance', 'reach' => 250000],
            ],
        ];

        return $similarMap[$tag] ?? $similarMap['rock'];
    }

    public function getTopTags(int $limit = 50): array
    {
        return [
            'tag' => [
                ['name' => 'rock', 'count' => 1254300],
                ['name' => 'pop', 'count' => 1180000],
                ['name' => 'electronic', 'count' => 980000],
                ['name' => 'hip hop', 'count' => 850000],
                ['name' => 'jazz', 'count' => 650000],
            ],
        ];
    }
}
