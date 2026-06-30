<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

#[OA\Schema(
    schema: 'RegisterServerRequest',
    required: ['serverUrl', 'name', 'version'],
    properties: [
        new OA\Property(property: 'serverUrl', type: 'string', format: 'uri'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'version', type: 'string'),
    ],
)]
final readonly class RegisterServerRequest
{
    public function __construct(
        #[NotBlank(message: 'Server URL is required.')]
        #[Url(message: 'Server URL must be a valid URL.')]
        public string $serverUrl = '',
        #[NotBlank(message: 'Server name is required.')]
        public string $name = '',
        #[NotBlank(message: 'Server version is required.')]
        public string $version = '',
    ) {
    }
}
