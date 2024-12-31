<?php

namespace App\Providers;

use App\Modules\Microservices\ReSpoolClientFactory;
use App\Modules\Microservices\ReSpoolProxy;
use Baander\Common\Microservices\IStreamService;
use Baander\ReSpool\Application;
use DI\Container;
use Illuminate\Support\ServiceProvider;

class MicroserviceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->scoped(ReSpoolProxy::class, function () {
            $factory = new ReSpoolClientFactory(config('services.respool.url'), config('services.respool.certificate_path'));

            return $factory->make();
        });

        $this->app->scoped(IStreamService::class, function (Container $app) {
            return $app->make(ReSpoolProxy::class)->remoteObjectFactory->createProxy(IStreamService::class);
        });
    }
}
