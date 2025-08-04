<?php

namespace App\Http\Integrations\MusicBrainz;

use App\Baander;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class Handler
{
    private const string RATE_LIMIT_CACHE_KEY = 'musicbrainz_last_request';
    private const string RATE_LIMIT_LOCK_KEY = 'musicbrainz_request_lock';
    private const int RATE_LIMIT_INTERVAL = 1; // 1 second between requests
    private const int LOCK_TIMEOUT = 5; // Max seconds to wait for lock
    private const int BACKOFF_AFTER_503 = 2; // Wait 2 seconds after receiving 503

    public function __construct(protected readonly Client $client, protected readonly string $baseUrl)
    {
    }

    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        $params += ['fmt' => 'json'];
        $lock = Cache::lock(self::RATE_LIMIT_LOCK_KEY, self::LOCK_TIMEOUT);

        try {
            if (!$lock->get()) {
                Log::warning('MusicBrainz request skipped - could not acquire lock', [
                    'endpoint' => $endpoint,
                    'timeout'  => self::LOCK_TIMEOUT,
                ]);
                return null;
            }

            $this->enforceRateLimit();

            // Record this request time while we still have the lock
            Cache::put(self::RATE_LIMIT_CACHE_KEY, microtime(true), 60);

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => Baander::getPeerName(),
            ];

            $url = $this->baseUrl . $endpoint;

            Log::debug('MusicBrainz API request', [
                'url'     => $url,
                'params'  => $params,
                'headers' => $headers,
            ]);

            $response = $this->client->getAsync($url, [
                'query'           => $params,
                'headers'         => $headers,
                'timeout'         => 30,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ])->wait();

            $statusCode = $response->getStatusCode();

            Log::debug('MusicBrainz API response', [
                'url'            => $url,
                'status_code'    => $statusCode,
                'content_length' => $response->getHeaderLine('Content-Length'),
            ]);

            if ($statusCode === 200) {
                $body = $response->getBody()->getContents();

                if (empty($body)) {
                    Log::warning('MusicBrainz returned empty response body', [
                        'url'         => $url,
                        'status_code' => $statusCode,
                    ]);
                    return null;
                }

                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('MusicBrainz returned invalid JSON', [
                        'url'          => $url,
                        'json_error'   => json_last_error_msg(),
                        'body_preview' => substr($body, 0, 200),
                    ]);
                    return null;
                }

                return $data;
            }

            // Handle MusicBrainz rate limiting (503 Service Unavailable)
            if ($statusCode === 503) {
                Log::warning('MusicBrainz rate limit exceeded (503)', [
                    'url'           => $url,
                    'status_code'   => $statusCode,
                    'response_body' => $response->getBody()->getContents(),
                ]);

                // Sleep for a bit longer when we get 503 to avoid hammering the server
                sleep(self::BACKOFF_AFTER_503);
                return null;
            }

            // Log other HTTP errors
            $errorMessage = match ($statusCode) {
                404 => 'Resource not found',
                429 => 'Too Many Requests (unexpected - MusicBrainz uses 503)',
                500, 502, 504 => 'MusicBrainz server error',
                default => "HTTP error {$statusCode}"
            };

            Log::warning('MusicBrainz API error', [
                'url'           => $url,
                'status_code'   => $statusCode,
                'error'         => $errorMessage,
                'response_body' => $response->getBody()->getContents(),
            ]);

            return null;

        } catch (GuzzleException $e) {
            Log::error('MusicBrainz HTTP request failed', [
                'url'         => $this->baseUrl . $endpoint,
                'error'       => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            return null;
        } catch (Exception $e) {
            Log::error('Unexpected error in MusicBrainz request', [
                'url'   => $this->baseUrl . $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        } finally {
            // Always release the lock
            $lock->release();
        }
    }

    /**
     * Enforce MusicBrainz rate limit of 1 request per second per IP
     */
    private function enforceRateLimit(): void
    {
        $lastRequestTime = Cache::get(self::RATE_LIMIT_CACHE_KEY);

        if ($lastRequestTime !== null) {
            $timeSinceLastRequest = microtime(true) - $lastRequestTime;

            if ($timeSinceLastRequest < self::RATE_LIMIT_INTERVAL) {
                $sleepTime = self::RATE_LIMIT_INTERVAL - $timeSinceLastRequest;

                Log::debug('MusicBrainz rate limit: sleeping', [
                    'sleep_time'              => $sleepTime,
                    'time_since_last_request' => $timeSinceLastRequest,
                ]);

                usleep($sleepTime * 1000000);
            }
        }
    }
}