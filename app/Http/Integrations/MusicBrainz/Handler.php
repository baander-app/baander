<?php

namespace App\Http\Integrations\MusicBrainz;

use GuzzleHttp\Client;

abstract class Handler
{
    public function __construct(protected readonly Client $client, protected readonly string $baseUrl)
    {}

    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        $response = $this->client->getAsync($this->baseUrl . $endpoint, [
            'query' => $params,
        ])->wait();

        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody()->getContents(), true);
        }

        return null;
    }
}