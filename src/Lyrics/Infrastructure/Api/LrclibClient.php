<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibRejected;
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
    /** Seconds a request may wait for data, and may take in all, before LRCLIB counts as unavailable. */
    private const TIMEOUT_SECONDS = 10;
    /** Client errors that mean "no record" or "try again later" rather than a refused request. */
    private const NOT_REJECTIONS = [404, 408, 429];

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
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null {
        return $this->fetchBySignature('/api/get-cached', $trackName, $artistName, $albumName, $duration);
    }

    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null {
        return $this->fetchBySignature('/api/get', $trackName, $artistName, $albumName, $duration);
    }

    public function getById(int $id): LrclibResult|LrclibRejected|LrclibUnavailable|null
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

    public function search(string $query): array|LrclibRejected|LrclibUnavailable
    {
        $this->logger->debug('LRCLIB search', [
            'service' => 'lrclib',
            'query' => $query,
        ]);

        $data = $this->request('GET', '/api/search', ['q' => $query]);

        if ($data === null) {
            return [];
        }
        if (!is_array($data)) {
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
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null {
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
            // LRCLIB takes whole seconds; rounding sends the second nearest the song's length.
            'duration' => round($duration) |> intval(...) |> strval(...),
        ]);

        if (!is_array($data)) {
            return $data;
        }

        return LrclibResult::fromApiResponse($data);
    }

    /**
     * Execute an HTTP request against the LRCLIB API.
     *
     * Returns null on 404 (no record). After logging a warning, returns LrclibRejected when
     * LRCLIB answers with a client error other than 404, 408 or 429, and LrclibUnavailable
     * when the request fails or times out, LRCLIB answers 408, 429, a redirect or a server
     * error, or the body is not JSON.
     * Never throws to callers.
     *
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>|LrclibRejected|LrclibUnavailable|null the decoded JSON response
     */
    private function request(string $method, string $endpoint, array $params = []): array|LrclibRejected|LrclibUnavailable|null
    {
        $url = $this->baseUrl . $endpoint;

        try {
            $response = $this->httpClient->request($method, $url, [
                'query' => $params,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300) {
                return $this->errorAnswer($endpoint, $statusCode, sprintf('HTTP %d', $statusCode));
            }

            return $response->toArray();
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            return $this->errorAnswer($endpoint, $statusCode, sprintf('HTTP %d: %s', $statusCode, $e->getMessage()));
        } catch (\Throwable $e) {
            return $this->unavailable($endpoint, $e->getMessage());
        }
    }

    /** What an error status means: no record, a refused request, or LRCLIB being unavailable. */
    private function errorAnswer(string $endpoint, int $statusCode, string $reason): LrclibRejected|LrclibUnavailable|null
    {
        return match (true) {
            $statusCode === 404 => null,
            self::isRejection($statusCode) => $this->rejected($endpoint, $statusCode, $reason),
            default => $this->unavailable($endpoint, $reason),
        };
    }

    private static function isRejection(int $statusCode): bool
    {
        return $statusCode >= 400 && $statusCode < 500 && !in_array($statusCode, self::NOT_REJECTIONS, true);
    }

    private function rejected(string $endpoint, int $statusCode, string $reason): LrclibRejected
    {
        $this->logger->warning('LRCLIB rejected the request', [
            'service' => 'lrclib',
            'endpoint' => $endpoint,
            'error' => $reason,
        ]);

        return new LrclibRejected($statusCode);
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
