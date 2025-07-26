<?php

namespace App\Http\Integrations\Spotify\Handlers;

use GuzzleHttp\Client;

class AuthHandler
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * Get authorization URL for Spotify OAuth
     */
    public function getAuthorizationUrl(string $clientId, string $redirectUri, string $state, array $scopes = []): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ];

        return 'https://accounts.spotify.com/authorize?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function getAccessToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        $response = $this->client->post('https://accounts.spotify.com/api/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken, string $clientId, string $clientSecret): array
    {
        $response = $this->client->post('https://accounts.spotify.com/api/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}