<?php

namespace App\Models;

use App\Packages\DeviceDetector\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JetBrains\PhpStorm\ArrayShape;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'user_agent',
        'client_name',
        'client_version',
        'client_type',
        'device_operating_system',
        'device_name',
        'device_brand_name',
        'device_model',
        'device_type',
    ];

    /**
     * Find the token instance matching the given token.
     *
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (!str_contains($token, '|')) {
            $token = Crypt::decryptString($token);
        }

        [$id, $token] = explode('|', $token, 2);

        if ($instance = static::find($id)) {
            return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
        }
    }

    #[ArrayShape([
        'user_agent'              => "null|string",
        'client_type'             => "array|null|string",
        'client_name'             => "array|null|string",
        'client_version'          => "array|null|string",
        'device_operating_system' => "null|string",
        'device_name'             => "null|string",
        'device_brand_name'       => "null|string",
        'device_model'            => "null|string",
        'device_type'             => "int|null",
    ])]
    public static function prepareDeviceFromRequest(Request $request)
    {
        $deviceDetector = Device::detectRequest($request);

        $osName = $deviceDetector->getOs('name');
        if ($osName === 'UNK') {
            $osName = null;
        }
        $osVersion = $deviceDetector->getOs('version');
        if ($osVersion === 'UNK') {
            $osVersion = null;
        }
        $deviceName = $deviceDetector->getDeviceName();
        $brandName = $deviceDetector->getBrandName();
        $model = $deviceDetector->getModel();
        $type = $deviceDetector->getDevice();

        return [
            'user_agent'              => $request->userAgent(),
            'client_type'             => $deviceDetector->getClient('type'),
            'client_name'             => $deviceDetector->getClient('name'),
            'client_version'          => $deviceDetector->getClient('version'),
            'device_operating_system' => $osName || $osVersion ? implode('|', [$osName, $osVersion]) : null,
            'device_name'             => $deviceName ?: null,
            'device_brand_name'       => $brandName ?: null,
            'device_model'            => $model ?: null,
            'device_type'             => $type ?: null,
        ];
    }
}