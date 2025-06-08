<?php

namespace App\Http\Integrations\Discogs;

use App\Baander;
use GuzzleHttp\Client;

abstract class Handler
{
    public function __construct(protected readonly Client $client, protected readonly string $baseUrl)
    {}

    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        if (config('services.discogs.api_key')) {
            $params['token'] = config('services.discogs.api_key');
        }

        $headers = [
            'User-Agent' => Baander::getPeerName(),
        ];

        $response = $this->client->getAsync($this->baseUrl . $endpoint, [
            'query' => $params,
            'headers' => $headers,
        ])->wait();

        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody()->getContents(), true);
        }

        return null;
    }
}