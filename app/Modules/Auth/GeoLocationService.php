<?php

namespace App\Modules\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeoLocationService
{
    private const CACHE_TTL = 3600; // 1 hour

    public function getLocationData(string $ipAddress): array
    {
        // Skip for localhost/private IPs
        if ($this->isPrivateIp($ipAddress)) {
            return [
                'country_code' => 'LOCAL',
                'country_name' => 'Local Network',
                'city' => 'Local',
                'is_private' => true,
            ];
        }

        $cacheKey = "geo_location_{$ipAddress}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($ipAddress) {
            return $this->fetchLocationData($ipAddress);
        });
    }

    private function fetchLocationData(string $ipAddress): array
    {
        try {
            // Using ip-api.com (free service, consider upgrading to paid service for production)
            $response = Http::timeout(5)->get("http://ip-api.com/json/{$ipAddress}", [
                'fields' => 'status,message,country,countryCode,city,query'
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['status'] === 'success') {
                    return [
                        'country_code' => $data['countryCode'] ?? null,
                        'country_name' => $data['country'] ?? null,
                        'city' => $data['city'] ?? null,
                        'is_private' => false,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch geo location data', [
                'ip' => $ipAddress,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback data
        return [
            'country_code' => null,
            'country_name' => 'Unknown',
            'city' => 'Unknown',
            'is_private' => false,
        ];
    }

    private function isPrivateIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public function hasCountryChanged(string $oldCountryCode, string $newCountryCode): bool
    {
        if (!$oldCountryCode || !$newCountryCode) {
            return false;
        }

        return $oldCountryCode !== $newCountryCode &&
            $oldCountryCode !== 'LOCAL' &&
            $newCountryCode !== 'LOCAL';
    }
}