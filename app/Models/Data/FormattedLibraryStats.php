<?php

namespace App\Models\Data;

use JsonSerializable;

readonly class FormattedLibraryStats implements JsonSerializable
{
    public function __construct(
        public string $totalSongs,
        public string $totalAlbums,
        public string $totalArtists,
        public string $totalGenres,
        public string $totalDuration,
        public string $totalSize,
        public string $libraryName,
    ) {}

    public static function fromRawStats(LibraryStats $stats, callable $formatDuration, callable $formatBytes): self
    {
        return new self(
            totalSongs: number_format($stats->totalSongs),
            totalAlbums: number_format($stats->totalAlbums),
            totalArtists: number_format($stats->totalArtists),
            totalGenres: number_format($stats->totalGenres),
            totalDuration: $formatDuration($stats->totalDuration),
            totalSize: $formatBytes($stats->totalSize),
            libraryName: $stats->libraryName,
        );
    }

    public function toArray(): array
    {
        return [
            'total_songs' => $this->totalSongs,
            'total_albums' => $this->totalAlbums,
            'total_artists' => $this->totalArtists,
            'total_genres' => $this->totalGenres,
            'total_duration' => $this->totalDuration,
            'total_size' => $this->totalSize,
            'library_name' => $this->libraryName,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
