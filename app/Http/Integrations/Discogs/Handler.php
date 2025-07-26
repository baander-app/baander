<?php

namespace App\Http\Integrations\Discogs;

use App\Baander;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;

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

        try {
            $response = $this->client->getAsync($this->baseUrl . $endpoint, [
                'query' => $params,
                'headers' => $headers,
                'http_errors' => false, // Don't throw exceptions for 4xx/5xx
            ])->wait();

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            // Only log detailed info for non-200 responses to reduce noise
            if ($statusCode !== 200) {
                Log::warning('Discogs API non-200 response', [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'response_body' => substr($body, 0, 200), // Limit body length
                    'rate_limit_remaining' => $response->getHeader('X-Discogs-Ratelimit-Remaining')[0] ?? 'unknown',
                ]);
            }

            if ($statusCode === 200) {
                return json_decode($body, true);
            }

            // Return null for all non-200 responses (including 500 errors)
            return null;

        } catch (\Exception $e) {
            Log::error('Discogs API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }


    protected function fetchEndpointAsync(string $endpoint, array $params = []): PromiseInterface
    {
        if (config('services.discogs.api_key')) {
            $params['token'] = config('services.discogs.api_key');
        }

        $headers = [
            'User-Agent' => Baander::getPeerName(),
        ];

        return $this->client->getAsync($this->baseUrl . $endpoint, [
            'query' => $params,
            'headers' => $headers,
            'http_errors' => false, // Don't throw exceptions for 4xx/5xx
        ])->then(function ($response) use ($endpoint) {
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }

            // Log non-200 responses
            Log::warning('Discogs API async non-200 response', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'response_body' => $response->getBody()->getContents()
            ]);

            return null;
        }, function ($exception) use ($endpoint) {
            // Log error and return null
            Log::error('Discogs API async request failed', [
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ]);
            return null;
        });
    }
}