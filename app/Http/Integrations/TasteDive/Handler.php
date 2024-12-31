<?php

namespace App\Http\Integrations\TasteDive;

use GuzzleHttp\Client;

abstract class Handler
{
    public function __construct(protected readonly Client $client, protected readonly string $baseUrl)
    {
    }

    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        $response = $this->client->getAsync($this->baseUrl . $endpoint, [
            'query'   => $params,
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.tastedive.api_key'),
            ],
        ])->wait();

        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody()->getContents(), true);
        }

        return null;
    }
}