<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceAuthorizationResource',
    description: 'Device authorization response (RFC 8628 section 3.2).',
    required: ['device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval'],
    properties: [
        new OA\Property(property: 'device_code', type: 'string'),
        new OA\Property(property: 'user_code', type: 'string', example: 'BCDF-GHJK'),
        new OA\Property(property: 'verification_uri', description: 'The web app page where the user enters the code (APP_URL/device)', type: 'string', format: 'uri', example: 'https://baander.app/device'),
        new OA\Property(property: 'verification_uri_complete', description: 'verification_uri with the user code as user_code', type: 'string', format: 'uri', example: 'https://baander.app/device?user_code=BCDF-GHJK'),
        new OA\Property(property: 'expires_in', type: 'integer', example: 900),
        new OA\Property(property: 'interval', description: 'Minimum seconds between token polls', type: 'integer', example: 5),
    ],
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
