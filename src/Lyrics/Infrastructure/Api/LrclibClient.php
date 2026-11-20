<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\DTO\LrclibWait;
use App\Lyrics\Application\Port\LrclibClientInterface;
use Baander\RedisRateLimiter\RateLimiterUnavailableException;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anti-corruption adapter for the LRCLIB public API.
 *
 * Translates external LRCLIB JSON responses into application DTOs
 * so the rest of the application never depends on LRCLIB data structures.
 *
 * LRCLIB limits the requests it takes from one address and names the wait when it answers
 * that they come too fast. Every request therefore first takes a turn under the shared
 * `lrclib` rate limit, which all processes keep in Redis, and no request is sent during a
 * pause LRCLIB asked for. A request answered with 429, or with 503 and a `Retry-After`
 * header, pauses every process, for an hour at most, and is sent again after the pause,
 * three times at most. A call has one deadline for all of this, its caller's wait: what
 * cannot be sent before it answers unavailable.
 *
 * API docs: https://lrclib.net/docs
 */
final readonly class LrclibClient implements LrclibClientInterface
{
    private const string DEFAULT_BASE_URL = 'https://lrclib.net';
    /** The name, version and contact address LRCLIB asks a client to send. */
    private const string USER_AGENT = 'Baander/1.0 (https://baander.app; contact@baander.app)';
    /** Seconds a request may wait for data, and may take in all, before LRCLIB counts as unavailable. */
    private const int TIMEOUT_SECONDS = 10;
    /** Client errors that mean "no record" or "try again later" rather than a refused request. */
    private const array NOT_REJECTIONS = [404, 408, 429];
    /** The one subject of the shared limit, so every process spends the same budget. */
    private const string LIMITER_KEY = 'lrclib.net';
    /** Times one call sends its request while LRCLIB answers that requests come too fast. */
    private const int MAX_SENDS = 3;
    /** Seconds to pause after a call's first and later rate-limit answers that name no wait. */
    private const array UNNAMED_PAUSE_SECONDS = [5, 15];
    /**
     * The longest pause one answer stores: a wait LRCLIB names above an hour is stored as an
     * hour, so no answer stops every process's lookups for longer.
     */
    private const int MAX_PAUSE_SECONDS = 3600;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep how the client waits; by default a sleep that
     *                                            yields the coroutine and lasts although signals arrive
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private RateLimiterFactoryInterface $lrclibLimiter,
        private LrclibPauseInterface $pause,
        private ClockInterface $clock,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? Async::sleepThroughSignals(...);
    }

    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
        LrclibWait $wait = LrclibWait::Interactive,
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null {
        $this->logger->debug('LRCLIB fetch by signature', [
            'service' => 'lrclib',
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName,
            'duration' => $duration,
        ]);

        $data = $this->request('/api/get', [
            'track_name' => $trackName,
            'artist_name' => $artistName,
            'album_name' => $albumName,
            // LRCLIB takes whole seconds; rounding sends the second nearest the song's length.
            'duration' => round($duration) |> intval(...) |> strval(...),
        ], $wait);

        return is_array($data) ? LrclibResult::fromApiResponse($data) : $data;
    }

    public function getById(int $id, LrclibWait $wait = LrclibWait::Interactive): LrclibResult|LrclibRejected|LrclibUnavailable|null
    {
        $this->logger->debug('LRCLIB fetch by ID', [
            'service' => 'lrclib',
            'id' => $id,
        ]);

        $data = $this->request("/api/get/{$id}", [], $wait);

        return is_array($data) ? LrclibResult::fromApiResponse($data) : $data;
    }

    public function search(string $query, LrclibWait $wait = LrclibWait::Interactive): array|LrclibRejected|LrclibUnavailable
    {
        $this->logger->debug('LRCLIB search', [
            'service' => 'lrclib',
            'query' => $query,
        ]);

        $data = $this->request('/api/search', ['q' => $query], $wait);

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
     * GET an LRCLIB endpoint within the caller's wait.
     *
     * Returns null on 404 (no record). After logging a warning, returns LrclibRejected when
     * LRCLIB answers with a client error other than 404, 408 or 429, and LrclibUnavailable
     * when the request cannot be sent within the wait, fails or times out, LRCLIB answers
     * 408, a redirect or a server error, still answers that requests come too fast after
     * three sends, or the body is not JSON.
     * Never throws to callers.
     *
     * @param array<string, string> $params
     *
     * @return array<array-key, mixed>|LrclibRejected|LrclibUnavailable|null the decoded JSON response
     */
    private function request(string $endpoint, array $params, LrclibWait $wait): array|LrclibRejected|LrclibUnavailable|null
    {
        return $this->attempt($endpoint, $params, $wait, $this->now() + $wait->seconds(), 1);
    }

    /**
     * Send the request once it may go out. A rate-limit answer pauses every process, and the
     * request is sent again after the pause until it has been sent three times.
     *
     * @param array<string, string> $params
     * @param float $deadline when the call stops waiting, in seconds since the epoch
     * @param positive-int $send which send of the call this is
     *
     * @return array<array-key, mixed>|LrclibRejected|LrclibUnavailable|null
     */
    private function attempt(string $endpoint, array $params, LrclibWait $wait, float $deadline, int $send): array|LrclibRejected|LrclibUnavailable|null
    {
        $response = $this->awaitTurn($endpoint, $wait, $deadline) ?? $this->send($endpoint, $params);
        if ($response instanceof LrclibUnavailable) {
            return $response;
        }
        [$status, $retryAfter, $body] = $response;
        if (!self::isRateLimit($status, $retryAfter)) {
            return $status < 300 ? $body : $this->errorAnswer($endpoint, $status);
        }

        $named = self::namedSeconds($retryAfter);
        $notStored = $this->pauseEveryProcess($endpoint, $status, self::pauseSeconds($named, $send), $named);
        if ($notStored !== null) {
            return $notStored;
        }
        if ($send >= self::MAX_SENDS) {
            return $this->unavailable($endpoint, sprintf('HTTP %d after %d requests', $status, $send));
        }

        return $this->attempt($endpoint, $params, $wait, $deadline, $send + 1);
    }

    /**
     * Wait until this call may send: for the end of a pause, then for its turn under the pace
     * every process shares, and once more when another process stored a pause meanwhile.
     * Answers unavailable, with nothing sent, when the pause or the turn ends after the
     * deadline or the shared state cannot be reached. A call a pause turns away takes no turn.
     */
    private function awaitTurn(string $endpoint, LrclibWait $wait, float $deadline): ?LrclibUnavailable
    {
        try {
            do {
                $paused = $this->pause->secondsLeft();
                if ($paused > $deadline - $this->now()) {
                    return $this->unavailable($endpoint, sprintf('paused for another %d seconds, longer than this call waits', (int) ceil($paused)));
                }
                ($this->sleep)($paused);
                $turn = $this->lrclibLimiter->create(self::LIMITER_KEY)->reserve(1, max(0.0, $deadline - $this->now()));
                ($this->sleep)($turn->getWaitDuration());
            } while ($this->pause->secondsLeft() > 0.0);
        } catch (MaxWaitDurationExceededException) {
            return $this->unavailable($endpoint, sprintf('no turn within %d seconds', (int) $wait->seconds()));
        } catch (RateLimiterUnavailableException) {
            return $this->unavailable($endpoint, 'the shared rate limit is unavailable');
        }

        return null;
    }

    /**
     * Send one GET.
     *
     * @param array<string, string> $params
     *
     * @return array{int, ?string, array<array-key, mixed>|null}|LrclibUnavailable the status, the
     *         Retry-After header and, for a success, the decoded body
     */
    private function send(string $endpoint, array $params): array|LrclibUnavailable
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . $endpoint, [
                'query' => $params,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();

            return [$status, $response->getHeaders(false)['retry-after'][0] ?? null, $status < 300 ? $response->toArray() : null];
        } catch (\Throwable $e) {
            return $this->unavailable($endpoint, $e->getMessage());
        }
    }

    /** LRCLIB answers that requests come too fast with 429, or with 503 when it names a wait. */
    private static function isRateLimit(int $status, ?string $retryAfter): bool
    {
        return $status === 429 || ($status === 503 && $retryAfter !== null);
    }

    /**
     * The whole seconds a Retry-After header names, or null when it is missing or gives
     * anything else, such as a date. A number too large for an integer is the largest one.
     */
    private static function namedSeconds(?string $retryAfter): ?int
    {
        return $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null;
    }

    /**
     * Seconds to pause after a rate-limit answer: the seconds it names, an hour at most, or a
     * fallback that grows with the call's sends when it names none.
     *
     * @param positive-int $send
     */
    private static function pauseSeconds(?int $named, int $send): int
    {
        return $named !== null
            ? min($named, self::MAX_PAUSE_SECONDS)
            : self::UNNAMED_PAUSE_SECONDS[min($send, count(self::UNNAMED_PAUSE_SECONDS)) - 1];
    }

    /**
     * Store the pause for every process and log when it ends, with the wait LRCLIB named when
     * the pause is shorter than that; answers unavailable when the pause cannot be stored.
     */
    private function pauseEveryProcess(string $endpoint, int $status, int $seconds, ?int $named): ?LrclibUnavailable
    {
        try {
            $left = $this->pause->pauseFor($seconds);
        } catch (RateLimiterUnavailableException) {
            return $this->unavailable($endpoint, sprintf('HTTP %d, and the pause it asks for cannot be stored', $status));
        }
        $this->logger->warning('LRCLIB answered that requests come too fast; every process pauses its requests', [
            'service' => 'lrclib',
            'endpoint' => $endpoint,
            'status' => $status,
            'pause_seconds' => $seconds,
            ...($named !== null && $named > $seconds ? ['retry_after_seconds' => $named] : []),
            'paused_until' => $this->clock->now()->modify(sprintf('+%d seconds', (int) ceil($left)))->format(\DATE_ATOM),
        ]);

        return null;
    }

    /** What an error status means: no record, a refused request, or LRCLIB being unavailable. */
    private function errorAnswer(string $endpoint, int $status): LrclibRejected|LrclibUnavailable|null
    {
        return match (true) {
            $status === 404 => null,
            self::isRejection($status) => $this->rejected($endpoint, $status),
            default => $this->unavailable($endpoint, sprintf('HTTP %d', $status)),
        };
    }

    private static function isRejection(int $status): bool
    {
        return $status >= 400 && $status < 500 && !in_array($status, self::NOT_REJECTIONS, true);
    }

    private function rejected(string $endpoint, int $status): LrclibRejected
    {
        $this->logger->warning('LRCLIB rejected the request', [
            'service' => 'lrclib',
            'endpoint' => $endpoint,
            'error' => sprintf('HTTP %d', $status),
        ]);

        return new LrclibRejected($status);
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

    /** The time on the client's clock, in seconds since the epoch. */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
