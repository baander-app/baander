<?php

namespace App\Providers;

use App\Baander;
use App\Http\Integrations\CoverArtArchive\CoverArtArchiveClient;
use App\Http\Integrations\LastFm\LastFmClient;
use App\Http\Integrations\MusicBrainz\MusicBrainzClient;
use App\Modules\Auth\LastFmCredentialService;
use App\Modules\Auth\ThirdPartyCredentialService;
use App\Services\GuzzleService;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MusicBrainz\HttpAdapter\GuzzleHttpAdapter;
use MusicBrainz\MusicBrainz;
use Psr\Log\LoggerInterface;

class IntegrationsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->scoped(CoverArtArchiveClient::class, function () {
            return new CoverArtArchiveClient(app(GuzzleService::class));
        });

        $this->app->scoped(MusicBrainz::class, function (Application $app) {
            $guzzle = new GuzzleHttpAdapter(new Client());
            $musicBrainz = new MusicBrainz($guzzle, $app->get(LoggerInterface::class)->channel('buggregator'));

            $musicBrainz->config()
                ->setUserAgent('Baander server/' . Baander::VERSION);

            return $musicBrainz;
        });

        $this->app->scoped(MusicBrainzClient::class, function () {
            return new MusicBrainzClient(app(GuzzleService::class));
        });

        $this->registerLastFm();
    }

    /**
     * Bootstrap services.
     */
    public function provides(): array
    {
        return [
            CoverArtArchiveClient::class,
            MusicBrainzClient::class,
            LastFmClient::class,
        ];
    }

    private function registerLastFm(): void
    {
        // Register the HTTP client for Last.fm
        $this->app->singleton('lastfm.http_client', function () {
            return new Client([
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => config('app.name', 'Baander') . ' Last.fm Client/' . config('app.version', '1.0.0'),
                ],
            ]);
        });

        // Register the credential service
        $this->app->scoped(LastFmCredentialService::class, function ($app) {
            return new LastFmCredentialService(
                $app->make(ThirdPartyCredentialService::class),
            );
        });

        // Register the main client
        $this->app->scoped(LastFmClient::class, function ($app) {
            return new LastFmClient(
                $app->make('lastfm.http_client'),
                $app->make(LastFmCredentialService::class),
            );
        });

    }
}
