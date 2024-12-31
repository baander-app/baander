<?php

namespace App\Providers;

use App\Baander;
use App\Repositories\Cache\CacheRepositoryInterface;
use App\Repositories\Cache\LaravelCacheRepository;
use Ergebnis\Clock\SystemClock;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\{DB, Log, URL};
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use MusicBrainz\HttpAdapter\GuzzleHttpAdapter;
use MusicBrainz\MusicBrainz;
use Psr\Log\LoggerInterface;

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

        $this->app->scoped(ImageManager::class, function () {
            return new ImageManager(new Driver());
        });

        $this->app->scoped(MusicBrainz::class, function (Application $app) {
            $guzzle = new GuzzleHttpAdapter(new Client());
            $musicBrainz = new MusicBrainz($guzzle, $app->get(LoggerInterface::class)->channel('buggregator'));

            $musicBrainz->config()
                ->setUserAgent('Baander server/' . Baander::VERSION);

            return $musicBrainz;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
        JsonResource::macro('paginationInformation', function ($request, $paginated, $default) {
            unset($default['links']);

            return $default;
        });

        URL::forceScheme('https');
    }
}
