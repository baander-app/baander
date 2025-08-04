<?php

namespace App\Http\Integrations\LastFm\Handlers;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class AuthHandler
{
    public function __construct(
        private readonly Client $client,
        private readonly string $baseUrl
    ) {}

    public function getAuthUrl(string $callbackUrl): string
    {
        $params = [
            'api_key' => config('services.lastfm.key'),
            'cb' => $callbackUrl,
        ];

        return 'https://www.last.fm/api/auth?' . http_build_query($params);
    }

    public function getToken(): ?string
    {
        $response = $this->client->get($this->baseUrl, [
            'query' => [
                'method' => 'auth.getToken',
                'api_key' => config('services.lastfm.key'),
                'format' => 'json',
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['token'] ?? null;
    }

    public function getSession(string $token): ?array
    {
        $params = [
            'method' => 'auth.getSession',
            'api_key' => config('services.lastfm.key'),
            'token' => $token,
            'format' => 'json',
        ];

        // Create API signature
        $params['api_sig'] = $this->generateSignature($params);

        $response = $this->client->get($this->baseUrl, ['query' => $params]);
        $data = json_decode($response->getBody()->getContents(), true);

        Log::info('Last.fm API response: ' . json_encode($data));

        return $data['session'] ?? null;
    }

    public function generateSignature(array $params): string
    {
        // Remove format and callback params from signature
        unset($params['format'], $params['callback']);

        // Sort parameters alphabetically by key
        ksort($params);

        // Create signature string
        $signatureString = '';
        foreach ($params as $key => $value) {
            $signatureString .= $key . $value;
        }

        // Append secret
        $signatureString .= config('services.lastfm.secret');

        // Return MD5 hash
        return md5($signatureString);
    }

    public function validateSession(string $sessionKey): bool
    {
        $params = [
            'method' => 'user.getInfo',
            'api_key' => config('services.lastfm.key'),
            'sk' => $sessionKey,
            'format' => 'json',
        ];

        $params['api_sig'] = $this->generateSignature($params);

        $response = $this->client->get($this->baseUrl, ['query' => $params]);
        $data = json_decode($response->getBody()->getContents(), true);

        return isset($data['user']);
    }

    public function getUserInfo(string $sessionKey): ?array
    {
        $params = [
            'method' => 'user.getInfo',
            'api_key' => config('services.lastfm.key'),
            'sk' => $sessionKey,
            'format' => 'json',
        ];

        $params['api_sig'] = $this->generateSignature($params);

        $response = $this->client->get($this->baseUrl, ['query' => $params]);
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['user'] ?? null;
    }
}