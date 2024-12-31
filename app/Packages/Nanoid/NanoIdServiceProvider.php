<?php

namespace App\Packages\Nanoid;

use Illuminate\Support\ServiceProvider;

class NanoIdServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->singleton(NanoIdService::class, NanoIdService::class);
    }
}