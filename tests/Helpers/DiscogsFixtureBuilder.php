<?php

namespace Tests\Helpers;

/**
 * Builder for Discogs fixtures with fluent genre/style configuration
 */
class DiscogsFixtureBuilder
{
    private array $fixtures = [];
    private array $currentStyles = [];

    /**
     * Start configuring a genre
     *
     * Returns a configurator that can be chained
     *
     * Example:
     * ->genre('rock')->hasStyles(['hard rock', 'classic rock', 'punk'])
     */
    public function genre(string $genre): self
    {
        $this->currentStyles = [];
        return $this;
    }

    /**
     * Add styles to the current genre
     *
     * Example: ->genre('rock')->hasStyles(['hard rock', 'punk'])
     */
    public function hasStyles(array $styles): self
    {
        $this->currentStyles = array_merge($this->currentStyles, $styles);
        return $this;
    }

    /**
     * Add a single style to the current genre
     *
     * Example: ->genre('rock')->addStyle('hard rock')->addStyle('punk')
     */
    public function addStyle(string $style): self
    {
        $this->currentStyles[] = $style;
        return $this;
    }

    /**
     * Build the current genre configuration
     */
    public function build(): self
    {
        // This is called automatically when needed
        return $this;
    }

    /**
     * Add a complete release search result
     */
    public function addRelease(string $genre, array $styles = []): self
    {
        $key = 'search_releases_' . strtolower(str_replace(' ', '-', $genre));

        if (!isset($this->fixtures[$key])) {
            $this->fixtures[$key] = ['results' => []];
        }

        $this->fixtures[$key]['results'][] = [
            'genre' => [$genre],
            'style' => $styles,
        ];

        return $this;
    }

    /**
     * Add multiple genres with their styles
     *
     * Example:
     * ->genres([
     *     'rock' => ['hard rock', 'classic rock', 'punk'],
     *     'electronic' => ['techno', 'house', 'ambient']
     * ])
     */
    public function genres(array $genreMap): self
    {
        foreach ($genreMap as $genre => $styles) {
            $this->addRelease($genre, (array) $styles);
        }
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
