<?php

namespace App\Http\Integrations\Spotify\Handlers;

use App\Modules\Auth\SpotifyCredentialService;
use GuzzleHttp\Client;

abstract class BaseHandler
{
    protected ?SpotifyCredentialService $credentialService = null;
    protected ?string $accessToken = null;

    public function __construct(
        protected readonly Client $client,
        protected readonly string $baseUrl
    ) {
    }

    /**
     * Set the credential service
     */
    public function setCredentialService(SpotifyCredentialService $credentialService): void
    {
        $this->credentialService = $credentialService;
    }

    /**
     * Set the access token
     */
    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Get the current access token
     */
    protected function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if ($this->credentialService && auth()->check()) {
            return $this->credentialService->getAccessToken(auth()->user());
        }

        return null;
    }

    /**
     * Get authorization headers
     */
    protected function getAuthHeaders(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [];
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Make an authenticated request
     */
    protected function makeRequest(string $method, string $endpoint, array $options = []): array
    {
        $headers = array_merge(
            $options['headers'] ?? [],
            $this->getAuthHeaders()
        );

        $options['headers'] = $headers;

        $response = $this->client->request($method, $this->baseUrl . $endpoint, $options);

        return json_decode($response->getBody()->getContents(), true);
    }
}