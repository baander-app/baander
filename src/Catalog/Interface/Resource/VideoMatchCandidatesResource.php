<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(type: 'string', enum: ['series', 'movie']),
        'publicId' => new OA\Schema(type: 'string'),
        'providers' => new OA\Schema(type: 'array', items: new OA\Schema(
            type: 'object',
            properties: [
                'provider' => new OA\Schema(type: 'string', enum: VideoMatchCandidatesResource::PROVIDERS),
                'problem' => new OA\Schema(type: 'string', nullable: true),
                'candidates' => new OA\Schema(type: 'array', items: new OA\Schema(
                    type: 'object',
                    properties: [
                        'id' => new OA\Schema(type: 'string'),
                        'title' => new OA\Schema(type: 'string'),
                        'year' => new OA\Schema(type: 'integer', nullable: true),
                        'externalIds' => new OA\Schema(type: 'object', additionalProperties: new OA\Schema(type: 'string')),
                    ],
                    required: ['id', 'title', 'year', 'externalIds'],
                )),
            ],
            required: ['provider', 'problem', 'candidates'],
        )),
    ],
    required: ['kind', 'publicId', 'providers'],
    component: 'VideoMatchCandidatesResource',
)]
final class VideoMatchCandidatesResource extends AbstractResource
{
    public const array PROVIDERS = ['tvdb', 'tmdb', 'local'];

    /** @return array<string, mixed> */
    public static function from(mixed $source): array
    {
        assert(is_array($source));
        if (($source['view'] ?? null) === 'choices') {
            return VideoMatchChoicesResource::from($source);
        }

        return ['kind' => $source['kind'], 'publicId' => $source['publicId'], 'providers' => $source['providers']];
    }
}
