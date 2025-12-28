<?php

namespace App\Providers;

use App\Http\Integrations\Transcoder\TranscoderClient;
use App\Modules\Auth\OAuth\OAuthServiceProvider;
use App\Repositories\Cache\CacheRepositoryInterface;
use App\Repositories\Cache\LaravelCacheRepository;
use DateTimeZone;
use Ergebnis\Clock\SystemClock;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(OAuthServiceProvider::class);

        // Register PSR HTTP Factory for OAuth server
        $this->app->singleton(PsrHttpFactory::class, function () {
            $psr17Factory = new Psr17Factory();

            return new PsrHttpFactory(
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory
            );
        });


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

        // Define OAuth token rate limiter - 60 requests per minute per IP
        RateLimiter::for('oauth-token', function (object $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                ->by($request->ip()?->toString() ?: $request->ip());
        });
    }
}
