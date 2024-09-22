<?php

namespace App\Http\Integrations\Github;

use App\Http\Integrations\Github\Dto\Repository;
use App\Http\Integrations\Github\Requests\{GetBaanderReleases, GetRepository};
use Saloon\Contracts\Sender;
use Saloon\Http\Connector;
use Saloon\Http\Senders\GuzzleSender;
use Saloon\Traits\Plugins\{AcceptsJson, HasTimeout};


class BaanderGhApi extends Connector
{
    use AcceptsJson, HasTimeout;

    protected int $connectTimeout = 10;
    protected int $requestTimeout = 10;

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.github.com';
    }

    /**
     * @return Repository
     * @throws \Saloon\Exceptions\Request\FatalRequestException
     * @throws \Saloon\Exceptions\Request\RequestException
     */
    public function getReleases()
    {
        $response = $this->send(new GetBaanderReleases());

        return $response->dto();
    }

    /**
     * @return Repository
     * @throws \Saloon\Exceptions\Request\FatalRequestException
     * @throws \Saloon\Exceptions\Request\RequestException
     */
    public function getBaanderRepo()
    {
        $response = $this->send(new GetRepository());

        return $response->dto();
    }

    /**
     * Default headers for every request
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Default HTTP client options
     */
    protected function defaultConfig(): array
    {
        return [];
    }

    protected function defaultSender(): Sender
    {
        return resolve(GuzzleSender::class);
    }
}
