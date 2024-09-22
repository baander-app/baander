<?php

namespace App\Providers;

use App\Repositories\Cache\CacheRepositoryInterface;
use App\Repositories\Cache\LaravelCacheRepository;
use Ergebnis\Clock\SystemClock;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\{Cache, DB, URL, View};
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Saloon\CachePlugin\Drivers\LaravelCacheDriver;
use Saloon\Http\Senders\GuzzleSender;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SystemClock::class, function () {
            $timeZone = new \DateTimeZone(config('app.timezone'));

            return new SystemClock($timeZone);
        });

        $this->app->scoped(CacheRepositoryInterface::class, LaravelCacheRepository::class);

        $this->app->scoped(LaravelCacheDriver::class, function () {
            return new LaravelCacheDriver(Cache::store(config('saloon.cache.store')));
        });

        $this->app->scoped(ImageManager::class, function () {
            return new ImageManager(new Driver());
        });

        $this->app->scoped(GuzzleSender::class, fn () => new GuzzleSender);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function ($query) {
            $previous = \Stopwatch::getDuration('Database') ?? 0;
            \Stopwatch::setDuration('Database', $previous + $query->time);
        });

        JsonResource::withoutWrapping();
        JsonResource::macro('paginationInformation', function ($request, $paginated, $default) {
            unset($default['links']);

            return $default;
        });

        URL::forceScheme('https');
    }
}
