<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Streaming\StreamService;
use App\Services\Streaming\Handlers\HLSStreamService;
use App\Services\Streaming\Handlers\DASHStreamService;
use Baander\Transcoder\Application;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;

class StreamServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->scoped(StreamService::class, function ($app) {
            $protocolHandlers = [
                'hls' => $app->make(HLSStreamService::class),
                'dash' => $app->make(DASHStreamService::class),
                // Add RTMP, WebRTC, or other protocols here
            ];

            return new StreamService($protocolHandlers);
        });

        $this->app->scoped(HLSStreamService::class, function ($app) {
            return new HLSStreamService($app->make(Application::class));
        });

        $this->app->scoped(DASHStreamService::class, function ($app) {
            return new DASHStreamService($app->make(Application::class));
        });
    }
}