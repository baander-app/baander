<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'DeviceAuthorizeRequest',
    required: ['clientId'],
    properties: [
        new OA\Property(property: 'clientId', type: 'string', example: 'client-uuid'),
        new OA\Property(property: 'scope', description: 'Requested OAuth scopes', type: 'string'),
    ],
)]
final readonly class DeviceAuthorizeRequest
{
    public function __construct(
        #[NotBlank(message: 'Client ID is required.')]
        public string $clientId = '',
        public string $scope = '',
    ) {
    }
}
