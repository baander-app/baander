<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

#[OA\Schema(
    type: 'object',
    properties: [
        'serverUrl' => new OA\Schema(
            type: 'string',
            format: 'uri',
            default: '',
        ),
        'name' => new OA\Schema(type: 'string', default: ''),
        'version' => new OA\Schema(type: 'string', default: ''),
    ],
    required: ['serverUrl', 'name', 'version'],
    component: 'RegisterServerRequest',
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
