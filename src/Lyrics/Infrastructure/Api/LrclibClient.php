<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
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
    ): LrclibResult|LrclibUnavailable|null {
        return $this->fetchBySignature('/api/get-cached', $trackName, $artistName, $albumName, $duration);
    }

    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): LrclibResult|LrclibUnavailable|null {
        return $this->fetchBySignature('/api/get', $trackName, $artistName, $albumName, $duration);
    }

    public function getById(int $id): LrclibResult|LrclibUnavailable|null
    {
        $this->logger->debug('LRCLIB fetch by ID', [
            'service' => 'lrclib',
            'id' => $id,
        ]);

        $data = $this->request('GET', "/api/get/{$id}");

        if (!is_array($data)) {
            return $data;
        }

        return LrclibResult::fromApiResponse($data);
    }

    public function search(string $query): array|LrclibUnavailable
    {
        $this->logger->debug('LRCLIB search', [
            'service' => 'lrclib',
            'query' => $query,
        ]);

        $data = $this->request('GET', '/api/search', ['q' => $query]);

        if ($data === null) {
            return [];
        }
        if ($data instanceof LrclibUnavailable) {
            return $data;
        }
        if (!array_is_list($data)) {
            return $this->unavailable('/api/search', 'the response is not a list');
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
    ): LrclibResult|LrclibUnavailable|null {
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

        if (!is_array($data)) {
            return $data;
        }

        return LrclibResult::fromApiResponse($data);
    }

    /**
     * Execute an HTTP request against the LRCLIB API.
     *
     * Returns null on 404 (no record), and LrclibUnavailable, after logging a warning, when
     * the request fails, LRCLIB answers with another error status, or the body is not JSON.
     * Never throws to callers.
     *
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>|LrclibUnavailable|null the decoded JSON response
     */
    private function request(string $method, string $endpoint, array $params = []): array|LrclibUnavailable|null
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
                return $this->unavailable($endpoint, sprintf('HTTP %d', $statusCode));
            }

            return $response->toArray();
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 404) {
                return null;
            }

            return $this->unavailable($endpoint, sprintf('HTTP %d: %s', $statusCode, $e->getMessage()));
        } catch (\Throwable $e) {
            return $this->unavailable($endpoint, $e->getMessage());
        }
    }

    private function unavailable(string $endpoint, string $reason): LrclibUnavailable
    {
        $this->logger->warning('LRCLIB API request failed', [
            'service' => 'lrclib',
            'endpoint' => $endpoint,
            'error' => $reason,
        ]);

        return new LrclibUnavailable($reason);
    }
}
