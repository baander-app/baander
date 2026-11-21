<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'name' => new OA\Schema(description: 'Client name', type: 'string'),
        'redirect' => new OA\Schema(description: 'JSON-encoded array of redirect URIs', type: 'string'),
        'personalAccessClient' => new OA\Schema(description: 'Whether this is a personal access client', type: 'boolean'),
        'confidential' => new OA\Schema(description: 'Whether the client is confidential', type: 'boolean'),
        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
    ],
    required: ['publicId', 'name', 'redirect', 'personalAccessClient', 'confidential', 'createdAt'],
    component: 'ClientResource',
)]
final class ClientResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Client);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'redirect' => json_encode($source->getRedirectUris()),
            'personalAccessClient' => $source->isPersonalAccessClient(),
            'confidential' => $source->isConfidential(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
