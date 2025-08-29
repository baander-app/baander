<?php

namespace App\Models\Data;

use JsonSerializable;

readonly class LibraryStats implements JsonSerializable
{
    public function __construct(
        public int $totalSongs,
        public int $totalAlbums,
        public int $totalArtists,
        public int $totalGenres,
        public int $totalDuration,
        public int $totalSize,
        public string $libraryName,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            totalSongs: $data['total_songs'] ?? 0,
            totalAlbums: $data['total_albums'] ?? 0,
            totalArtists: $data['total_artists'] ?? 0,
            totalGenres: $data['total_genres'] ?? 0,
            totalDuration: $data['total_duration'] ?? 0,
            totalSize: $data['total_size'] ?? 0,
            libraryName: $data['library_name'] ?? '',
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
