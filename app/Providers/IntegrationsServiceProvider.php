<?php

namespace App\Providers;

use App\Http\Integrations\CoverArtArchive\CoverArtArchiveClient;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Services\GuzzleService;
use Illuminate\Support\ServiceProvider;

class IntegrationsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(CoverArtArchiveClient::class, function () {
            return new CoverArtArchiveClient(app(GuzzleService::class)->getClient());
        });

        $this->app->singleton(MusicBrainzClient::class, function () {
            return new MusicBrainzClient(app(GuzzleService::class)->getClient());
        });
    }

    /**
     * Bootstrap services.
     */
    public function provides(): array
    {
        return [
          CoverArtArchiveClient::class,
          MusicBrainzClient::class,
        ];
    }
}
