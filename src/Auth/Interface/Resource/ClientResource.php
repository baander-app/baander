<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClientResource',
    properties: [
        new OA\Property(property: 'publicId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', description: 'Public identifier'),
        new OA\Property(property: 'name', type: 'string', description: 'Client name'),
        new OA\Property(property: 'redirect', type: 'string', description: 'JSON-encoded array of redirect URIs'),
        new OA\Property(property: 'personalAccessClient', type: 'boolean', description: 'Whether this is a personal access client'),
        new OA\Property(property: 'confidential', type: 'boolean', description: 'Whether the client is confidential'),
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
