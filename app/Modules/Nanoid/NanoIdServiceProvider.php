<?php

namespace App\Modules\Nanoid;

use Illuminate\Support\ServiceProvider;

class NanoIdServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->singleton(NanoIdService::class, NanoIdService::class);
    }
}