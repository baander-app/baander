<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'Device authorization response (RFC 8628 section 3.2).',
    type: 'object',
    properties: [
        'device_code' => new OA\Schema(type: 'string'),
        'user_code' => new OA\Schema(type: 'string', example: 'BCDF-GHJK'),
        'verification_uri' => new OA\Schema(
            description: 'The web app page where the user enters the code (APP_URL/device)',
            type: 'string',
            format: 'uri',
            example: 'https://baander.app/device',
        ),
        'verification_uri_complete' => new OA\Schema(
            description: 'verification_uri with the user code as user_code',
            type: 'string',
            format: 'uri',
            example: 'https://baander.app/device?user_code=BCDF-GHJK',
        ),
        'expires_in' => new OA\Schema(type: 'integer', example: 900),
        'interval' => new OA\Schema(
            description: 'Minimum seconds between token polls',
            type: 'integer',
            example: 5,
        ),
    ],
    required: [
        'device_code',
        'user_code',
        'verification_uri',
        'verification_uri_complete',
        'expires_in',
        'interval',
    ],
    component: 'DeviceAuthorizationResource',
)]
final class DeviceAuthorizationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof DeviceAuthorizationDTO);

        return [
            'device_code' => $source->deviceCode,
            'user_code' => $source->userCode,
            'verification_uri' => $source->verificationUri,
            'verification_uri_complete' => $source->verificationUriComplete,
            'expires_in' => $source->expiresIn,
            'interval' => $source->interval,
        ];
    }
}
