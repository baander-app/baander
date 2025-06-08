<?php

namespace App\Http\Integrations\Discogs\Models;

class Master extends Model
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
        public ?array $tracklist = null,
        public ?array $videos = null,
        public ?array $genres = null,
        public ?array $styles = null,
        public ?int $year = null,
        public ?string $data_quality = null,
        public ?int $versions_count = null,
        public ?string $main_release = null,
        public ?string $main_release_url = null,
        public ?string $notes = null,
        public ?int $num_for_sale = null,
        public ?float $lowest_price = null
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
            tracklist: $data['tracklist'] ?? null,
            videos: $data['videos'] ?? null,
            genres: $data['genres'] ?? null,
            styles: $data['styles'] ?? null,
            year: $data['year'] ?? null,
            data_quality: $data['data_quality'] ?? null,
            versions_count: $data['versions_count'] ?? null,
            main_release: $data['main_release'] ?? null,
            main_release_url: $data['main_release_url'] ?? null,
            notes: $data['notes'] ?? null,
            num_for_sale: $data['num_for_sale'] ?? null,
            lowest_price: $data['lowest_price'] ?? null
        );
    }
}