<?php

namespace App\Modules\BlurHash;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class BlurHashServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->scoped('blurhash', function (Application $app) {
            $config = $app->get('config')->get('blurhash');

            return new BlurHash(
                $config['driver'] ?? 'gd',
                $config['components-x'],
                $config['components-y'],
                $config['resized-max-size'] ?? $config['resized-image-max-width'],
            );
        });
    }
}
