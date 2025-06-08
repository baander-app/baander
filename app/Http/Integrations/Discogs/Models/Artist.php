<?php

namespace App\Http\Integrations\Discogs\Models;

class Artist extends Model
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
        public ?array $urls = null,
        public ?array $members = null,
        public ?string $data_quality = null,
        public ?string $namevariations = null,
        public ?array $aliases = null,
        public ?int $releases_count = null
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
            urls: $data['urls'] ?? null,
            members: $data['members'] ?? null,
            data_quality: $data['data_quality'] ?? null,
            namevariations: $data['namevariations'] ?? null,
            aliases: $data['aliases'] ?? null,
            releases_count: $data['releases_count'] ?? null
        );
    }
}