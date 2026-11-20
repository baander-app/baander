<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClientResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Client name', type: 'string'),
        new OA\Property(property: 'redirect', description: 'JSON-encoded array of redirect URIs', type: 'string'),
        new OA\Property(property: 'personalAccessClient', description: 'Whether this is a personal access client', type: 'boolean'),
        new OA\Property(property: 'confidential', description: 'Whether the client is confidential', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
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
