<?php

namespace App\Http\Integrations\Discogs;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Baander;

abstract class Handler
{
    private const string RATE_LIMIT_CACHE_KEY = 'discogs_rate_limit';
    private const int DEFAULT_RETRY_AFTER = 60; // seconds

    public function __construct(protected readonly Client $client, protected readonly string $baseUrl)
    {}

    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        // Check if we're currently rate limited and wait if needed
        if (!$this->canMakeRequest()) {
            if (!$this->waitForRateLimit()) {
                // Still rate limited after waiting
                Log::warning('Discogs API request skipped after rate limit wait timeout', [
                    'endpoint' => $endpoint,
                    'rate_limit_expires' => Cache::get(self::RATE_LIMIT_CACHE_KEY . '_expires')
                ]);
                return null;
            }
        }

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
            $rateLimitRemaining = $response->getHeader('X-Discogs-Ratelimit-Remaining')[0] ?? 'unknown';

            // Handle rate limiting
            if ($statusCode === 429) {
                $retryAfter = $response->getHeader('Retry-After')[0] ?? self::DEFAULT_RETRY_AFTER;
                $this->handleRateLimit($endpoint, (int)$retryAfter, $rateLimitRemaining);
                return null;
            }

            // Only log detailed info for non-200 responses to reduce noise
            if ($statusCode !== 200) {
                Log::warning('Discogs API non-200 response', [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'response_body' => substr($body, 0, 200), // Limit body length
                    'rate_limit_remaining' => $rateLimitRemaining,
                ]);
            }

            // Log rate limit info when getting low
            if (is_numeric($rateLimitRemaining) && (int)$rateLimitRemaining < 10) {
                Log::warning('Discogs rate limit getting low', [
                    'endpoint' => $endpoint,
                    'rate_limit_remaining' => $rateLimitRemaining
                ]);
            }

            if ($statusCode === 200) {
                return json_decode($body, true);
            }

            // Return null for all non-200 responses (including 500 errors)
            return null;

        } catch (Exception $e) {
            Log::error('Discogs API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    protected function fetchEndpointAsync(string $endpoint, array $params = []): PromiseInterface
    {
        // Check if we're currently rate limited
        if (Cache::has(self::RATE_LIMIT_CACHE_KEY)) {
            Log::warning('Discogs API async request skipped due to rate limiting', [
                'endpoint' => $endpoint,
                'rate_limit_expires' => Cache::get(self::RATE_LIMIT_CACHE_KEY . '_expires')
            ]);
            return Create::promiseFor(null);
        }

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
            $body = $response->getBody()->getContents();
            $rateLimitRemaining = $response->getHeader('X-Discogs-Ratelimit-Remaining')[0] ?? 'unknown';

            // Handle rate limiting
            if ($statusCode === 429) {
                $retryAfter = $response->getHeader('Retry-After')[0] ?? self::DEFAULT_RETRY_AFTER;
                $this->handleRateLimit($endpoint, (int)$retryAfter, $rateLimitRemaining);
                return null;
            }

            if ($statusCode === 200) {
                // Log rate limit info when getting low
                if (is_numeric($rateLimitRemaining) && (int)$rateLimitRemaining < 10) {
                    Log::warning('Discogs rate limit getting low (async)', [
                        'endpoint' => $endpoint,
                        'rate_limit_remaining' => $rateLimitRemaining
                    ]);
                }

                return json_decode($body, true);
            }

            // Log non-200 responses
            Log::warning('Discogs API async non-200 response', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'response_body' => substr($body, 0, 200),
                'rate_limit_remaining' => $rateLimitRemaining,
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

    private function handleRateLimit(string $endpoint, int $retryAfter, string $rateLimitRemaining): void
    {
        $expiresAt = now()->addSeconds($retryAfter);

        Cache::put(self::RATE_LIMIT_CACHE_KEY, true, $expiresAt);
        Cache::put(self::RATE_LIMIT_CACHE_KEY . '_expires', $expiresAt->toISOString(), $expiresAt);

        Log::warning('Discogs rate limit hit, caching rate limit status', [
            'endpoint' => $endpoint,
            'retry_after' => $retryAfter,
            'rate_limit_remaining' => $rateLimitRemaining,
            'rate_limit_expires' => $expiresAt->toISOString()
        ]);
    }

    /**
     * Check if we can make a Discogs request (not rate limited)
     */
    public function canMakeRequest(): bool
    {
        return !Cache::has(self::RATE_LIMIT_CACHE_KEY);
    }

    /**
     * Wait for rate limit to expire before making requests
     *
     * Respects the actual expires_at time from rate limit headers.
     * Has a safety net of max attempts in case something goes wrong.
     *
     * @return bool True if we can proceed, false if timeout reached
     */
    public function waitForRateLimit(): bool
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->canMakeRequest()) {
                return true;
            }

            $expiresAt = Cache::get(self::RATE_LIMIT_CACHE_KEY . '_expires');
            $remaining = $expiresAt ? now()->diffInSeconds($expiresAt) : 0;

            if ($remaining <= 0) {
                // Should be expired but cache hasn't cleared, try to proceed
                return true;
            }

            // Wait for the full remaining time (up to 60 seconds)
            // This respects the actual rate limit expires_at time
            $waitTime = min($remaining, 60);

            Log::info("Waiting for Discogs rate limit (attempt $attempt/$maxAttempts)", [
                'seconds_remaining' => $remaining,
                'waiting_seconds' => $waitTime,
                'expires_at' => $expiresAt,
            ]);

            sleep($waitTime);
        }

        // Final check after all attempts
        $canProceed = $this->canMakeRequest();

        if (!$canProceed) {
            Log::warning('Discogs rate limit wait timeout exceeded', [
                'attempts' => $maxAttempts,
                'expires_at' => Cache::get(self::RATE_LIMIT_CACHE_KEY . '_expires'),
            ]);
        }

        return $canProceed;
    }

    /**
     * Get rate limit status information
     */
    public function getRateLimitStatus(): array
    {
        return [
            'is_rate_limited' => Cache::has(self::RATE_LIMIT_CACHE_KEY),
            'expires_at' => Cache::get(self::RATE_LIMIT_CACHE_KEY . '_expires'),
        ];
    }
}