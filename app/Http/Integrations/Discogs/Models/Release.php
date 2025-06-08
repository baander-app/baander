<?php

namespace App\Http\Integrations\Discogs\Models;

class Release extends Model
{
    public function __construct(
        public ?int $id = null,
        public ?string $title = null,
        public ?string $uri = null,
        public ?string $resource_url = null,
        public ?string $type = null,
        public ?string $thumbnail = null,
        public ?string $cover_image = null,
        public ?array $images = null,
        public ?array $artists = null,
        public ?array $formats = null,
        public ?array $labels = null,
        public ?array $tracklist = null,
        public ?string $country = null,
        public ?string $released = null,
        public ?int $year = null,
        public ?string $notes = null,
        public ?string $data_quality = null,
        public ?array $genres = null,
        public ?array $styles = null,
        public ?string $master_id = null,
        public ?string $master_url = null,
        public ?string $catno = null
    ) {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            title: $data['title'] ?? null,
            uri: $data['uri'] ?? null,
            resource_url: $data['resource_url'] ?? null,
            type: $data['type'] ?? null,
            thumbnail: $data['thumb'] ?? null,
            cover_image: $data['cover_image'] ?? null,
            images: $data['images'] ?? null,
            artists: $data['artists'] ?? null,
            formats: $data['formats'] ?? null,
            labels: $data['labels'] ?? null,
            tracklist: $data['tracklist'] ?? null,
            country: $data['country'] ?? null,
            released: $data['released'] ?? null,
            year: $data['year'] ?? null,
            notes: $data['notes'] ?? null,
            data_quality: $data['data_quality'] ?? null,
            genres: $data['genres'] ?? null,
            styles: $data['styles'] ?? null,
            master_id: $data['master_id'] ?? null,
            master_url: $data['master_url'] ?? null,
            catno: $data['catno'] ?? null
        );
    }
}