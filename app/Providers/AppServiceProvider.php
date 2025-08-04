<?php

namespace App\Providers;

use App\Http\Integrations\Transcoder\TranscoderClient;
use App\Repositories\Cache\CacheRepositoryInterface;
use App\Repositories\Cache\LaravelCacheRepository;
use DateTimeZone;
use Ergebnis\Clock\SystemClock;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SystemClock::class, function () {
            $timeZone = new DateTimeZone(config('app.timezone'));

            return new SystemClock($timeZone);
        });

        $this->app->scoped(CacheRepositoryInterface::class, LaravelCacheRepository::class);

        $this->app->scoped(ImageManager::class, function () {
            return new ImageManager(new Driver());
        });

        $this->app->scoped(TranscoderClient::class, function (Application $app) {
            $guzzle = new Client();
            return new TranscoderClient(
                client: $guzzle,
                baseUrl: 'http://' . config('transcoder.host') . ':' . config('transcoder.port'),
            );
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
