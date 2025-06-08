<?php

namespace App\Http\Integrations\Discogs\Models;

class Label extends Model
{
    public function __construct(
        public ?int $id = null,
        public ?string $name = null,
        public ?string $title = null,
        public ?string $profile = null,
        public ?string $uri = null,
        public ?string $resource_url = null,
        public ?string $type = null,
        public ?string $thumbnail = null,
        public ?string $cover_image = null,
        public ?array $images = null,
        public ?string $contact_info = null,
        public ?string $parent_label = null,
        public ?array $sublabels = null,
        public ?array $urls = null,
        public ?string $data_quality = null,
        public ?int $releases_count = null,
        public ?string $country = null
    ) {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            name: $data['name'] ?? null,
            title: $data['title'] ?? null,
            profile: $data['profile'] ?? null,
            uri: $data['uri'] ?? null,
            resource_url: $data['resource_url'] ?? null,
            type: $data['type'] ?? null,
            thumbnail: $data['thumb'] ?? null,
            cover_image: $data['cover_image'] ?? null,
            images: $data['images'] ?? null,
            contact_info: $data['contact_info'] ?? null,
            parent_label: $data['parent_label'] ?? null,
            sublabels: $data['sublabels'] ?? null,
            urls: $data['urls'] ?? null,
            data_quality: $data['data_quality'] ?? null,
            releases_count: $data['releases_count'] ?? null,
            country: $data['country'] ?? null
        );
    }
}