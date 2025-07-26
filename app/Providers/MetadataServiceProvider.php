<?php

namespace App\Providers;

use App\Modules\Metadata\MetadataJobDispatcher;
use Illuminate\Support\ServiceProvider;

class MetadataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetadataJobDispatcher::class, function ($app) {
            return new MetadataJobDispatcher(
                defaultBatchSize: config('metadata.sync.batch_size', 10),
                defaultQueue: config('metadata.sync.queue', 'default')
            );
        });
    }
}
