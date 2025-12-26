<?php

namespace App\Modules\Metadata;

use App\Modules\Metadata\Contracts\FormatDetectorInterface;
use App\Modules\Metadata\Readers\FormatDetector;
use Illuminate\Support\ServiceProvider;

class MetadataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FormatDetectorInterface::class, FormatDetector::class);

        $this->app->singleton(MetadataJobDispatcher::class, function ($app) {
            return new MetadataJobDispatcher(
                defaultBatchSize: config('metadata.sync.batch_size', 10),
                defaultQueue: config('metadata.sync.queue', 'default'),
            );
        });
    }
}
