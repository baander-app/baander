<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\Port\LrclibClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anti-corruption adapter for the LRCLIB public API.
 *
 * Translates external LRCLIB JSON responses into application DTOs
 * so the rest of the application never depends on LRCLIB data structures.
 *
 * API docs: https://lrclib.net/docs
 */
final class LrclibClient implements LrclibClientInterface
{
    private const DEFAULT_BASE_URL = 'https://lrclib.net';
    private const USER_AGENT = 'Baander';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    public function getBySignatureCached(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): ?LrclibResult {
        return $this->fetchBySignature('/api/get-cached', $trackName, $artistName, $albumName, $duration);
    }

    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): ?LrclibResult {
        return $this->fetchBySignature('/api/get', $trackName, $artistName, $albumName, $duration);
    }

    public function getById(int $id): ?LrclibResult
    {
        $this->logger->debug('LRCLIB fetch by ID', [
            'service' => 'lrclib',
            'id' => $id,
        ]);

        $data = $this->request('GET', "/api/get/{$id}");

        if ($data === null) {
            return null;
        }

        return LrclibResult::fromApiResponse($data);
    }

    public function search(string $query): array
    {
        $this->logger->debug('LRCLIB search', [
            'service' => 'lrclib',
            'query' => $query,
        ]);

        $data = $this->request('GET', '/api/search', ['q' => $query]);

        if ($data === null || !array_is_list($data)) {
            return [];
        }

        return array_map(
            static fn(array $item): LrclibSearchResult => LrclibSearchResult::fromApiResponse($item),
            $data,
        );
    }

    /**
     * Fetch lyrics by track signature from a specific endpoint.
     */
    private function fetchBySignature(
        string $endpoint,
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): ?LrclibResult {
        $this->logger->debug('LRCLIB fetch by signature', [
            'service' => 'lrclib',
            'endpoint' => $endpoint,
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName,
            'duration' => $duration,
        ]);

        $data = $this->request('GET', $endpoint, [
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName,
            'duration' => (string) (int) $duration,
        ]);

        if ($data === null) {
            return null;
        }

        return LrclibResult::fromApiResponse($data);
    }

    /**
     * Execute an HTTP request against the LRCLIB API.
     *
     * Returns null on 404 (no lyrics found), logs errors on other failures,
     * and never throws external exceptions to callers.
     *
     * @return array<string, mixed>|null Decoded JSON response, or null on failure/404
     */
    private function request(string $method, string $endpoint, array $params = []): ?array
    {
        $url = $this->baseUrl . $endpoint;

        try {
            $response = $this->httpClient->request($method, $url, [
                'query' => $params,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return null;
            }

            if ($statusCode >= 400) {
                $this->logger->warning('LRCLIB API error response', [
                    'service' => 'lrclib',
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                ]);

                return null;
            }

            $data = $response->toArray();

            return is_array($data) ? $data : null;
        } catch (ClientException $e) {
            $response = $e->getResponse();

            if ($response->getStatusCode() === 404) {
                return null;
            }

            $this->logger->warning('LRCLIB API client error', [
                'service' => 'lrclib',
                'endpoint' => $endpoint,
                'status_code' => $response->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('LRCLIB API request failed', [
                'service' => 'lrclib',
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
