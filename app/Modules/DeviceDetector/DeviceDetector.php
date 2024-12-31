<?php

namespace App\Modules\DeviceDetector;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector as MatomoDeviceDetector;
use Illuminate\Http\Request;

class DeviceDetector
{
    public function __construct(
        protected CacheRepository $cacheRepository,
    )
    {
    }

    public function detect(string $userAgent, array $headers = []): MatomoDeviceDetector
    {
        $clientHints = ClientHints::factory(headers: $headers);

        $dd = new MatomoDeviceDetector(
            userAgent: $userAgent,
            clientHints: $clientHints,
        );

        $dd->setCache(cache: $this->cacheRepository);

        $dd->parse();

        return $dd;
    }

    public function detectRequest(Request $request): MatomoDeviceDetector
    {
        return $this->detect(
            userAgent: $request->userAgent() ?? '',
            headers: (array)$request->server(),
        );
    }
}