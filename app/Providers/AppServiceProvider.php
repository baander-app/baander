<?php

namespace App\Providers;

use App\Extensions\JsonAnonymousResourceCollection;
use App\Extensions\JsonPaginatedResourceResponse;
use App\Packages\JsonSchema\Validation\DefaultValidationRuleProvider;
use App\Packages\JsonSchema\Validation\ValidationRuleProviderInterface;
use App\Repositories\Cache\CacheRepositoryInterface;
use App\Repositories\Cache\LaravelCacheRepository;
use App\View\Composers\BaanderViewComposer;
use Ergebnis\Clock\SystemClock;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\{DB, URL, View};
use Illuminate\Http\Resources\Json\PaginatedResourceResponse;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SystemClock::class, function () {
            $timeZone = new \DateTimeZone(config('app.timezone'));

            return new SystemClock($timeZone);
        });

        $this->app->singleton(CacheRepositoryInterface::class, LaravelCacheRepository::class);
        $this->app->singleton(ValidationRuleProviderInterface::class, DefaultValidationRuleProvider::class);
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

        View::composer(['auth.layout', 'app'], BaanderViewComposer::class);
    }
}
