<?php

namespace Tests\Helpers;

/**
 * Builder for LastFM fixtures with sensible defaults
 */
class LastFmFixtureBuilder
{
    private array $fixtures = [];
    private int $defaultReach = 1_000_000;

    /**
     * Add a LastFM tag fixture
     *
     * @param string $tag Genre/tag name
     * @param int $reach Popularity reach (default: 1,000,000)
     * @param string|null $summary Wiki summary
     * @param string|null $name Override name (defaults to $tag)
     */
    public function tag(
        string $tag,
        int $reach = 0,
        ?string $summary = null,
        ?string $name = null,
    ): self {
        $key = 'tag_getInfo_' . strtolower(str_replace(' ', '-', $tag));

        $this->fixtures[$key] = [
            'tag' => [
                'name' => $name ?? $tag,
                'reach' => $reach ?: $this->defaultReach,
                'url' => "https://www.last.fm/tag/" . strtolower(str_replace(' ', '-', $tag)),
                'taggings' => $reach * 5, // Fake taggings count
                'streamable' => '1',
                'wiki' => [
                    'summary' => $summary ?? "Mock summary for {$tag}",
                    'content' => null,
                ],
            ],
        ];

        return $this;
    }

    /**
     * Add multiple tags at once
     *
     * Example: ->tags(['rock', 'electronic', 'jazz'], reach: 500_000)
     */
    public function tags(array $tags, int $reach = 0): self
    {
        foreach ($tags as $tag) {
            $this->tag($tag, reach: $reach);
        }
        return $this;
    }

    /**
     * Set default reach for subsequent tags
     */
    public function withDefaultReach(int $reach): self
    {
        $this->defaultReach = $reach;
        return $this;
    }

    /**
     * Override a nested value using dot notation
     */
    public function override(array $path, mixed $value): void
    {
        $key = $path[0];
        $remaining = array_slice($path, 1);

        if (!isset($this->fixtures[$key])) {
            $this->fixtures[$key] = [];
        }

        $target = &$this->fixtures[$key];
        foreach ($remaining as $segment) {
            if (!isset($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }

    public function toArray(): array
    {
        return $this->fixtures;
    }
}
