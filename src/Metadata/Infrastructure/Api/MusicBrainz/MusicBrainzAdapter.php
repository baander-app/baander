<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzDisabled;
use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzLabel;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMediumSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecordingRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzArtistDto;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzRecordingDto;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzReleaseGroupDto;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzSearchResultDto;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Baander\HttpClient\PublicHttpClient;
use App\Shared\Infrastructure\Swoole\Async;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Anti-corruption layer for the MusicBrainz web service.
 *
 * Translates MusicBrainz JSON responses into DTOs, insulating the rest of the application
 * from the API contract. It implements the lookup port that matching uses, and keeps the
 * older search and lookup methods of the metadata enrichers and the open metadata routes.
 *
 * MusicBrainz requires a meaningful User-Agent and allows one request per second from an
 * address. Every request, including the Cover Art Archive requests made for the port, first
 * takes a turn under the shared `musicbrainz` rate limit, which all processes keep in Redis.
 * MusicBrainz also answers 503 now and then when it is busy, so a MusicBrainz request that gets
 * one is asked again after a back-off, on a fresh turn. It answers the lookup of an ID it merged
 * into another record with a permanent redirect to that record; an artist lookup reports the
 * surviving ID without following it, and every other redirect counts as no answer.
 */
final readonly class MusicBrainzAdapter implements MusicBrainzLookupInterface
{
    private const string BASE_URL = 'https://musicbrainz.org/ws/2';
    /** The host and path of BASE_URL. */
    private const string HOST = 'musicbrainz.org';
    private const string LOOKUP_PATH = '/ws/2';
    private const string USER_AGENT = 'Baander/1.0 (https://baander.app; contact@baander.app)';
    /** Seconds a request may take before MusicBrainz counts as unavailable. */
    private const int TIMEOUT_SECONDS = 10;
    /** Hits a release, recording or artist search of the port asks for. */
    private const int SEARCH_LIMIT = 25;
    /** The one subject of the shared limit, so every process spends the same budget. */
    private const string LIMITER_KEY = 'musicbrainz.org';
    /** Client errors that mean "try again later" rather than a refused request. */
    private const array NOT_REJECTIONS = [408, 429];
    /** MusicBrainz's documented answer to a client that is too fast, and its answer when busy. */
    private const int SLOW_DOWN = 503;
    /** MusicBrainz's answer to the lookup of a merged ID: a permanent redirect to the surviving record. */
    private const int MERGED = 301;
    /** A MusicBrainz ID in a lookup path. */
    private const string MBID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    /** Seconds to wait before each retry of a request answered with 503. */
    private const array RETRY_BACK_OFF_SECONDS = [1.0, 3.0];
    private const string RELEASE_INCLUDES = 'recordings+artist-credits+labels+release-groups+genres';
    private const string RECORDING_INCLUDES = 'artist-credits+releases+release-groups';
    /** Characters with a meaning in Lucene query syntax; a backslash makes each one plain text. */
    private const string LUCENE_SPECIALS = '/[+\-&|!(){}\[\]^"~*?:\\\\\/]/';

    /** @param list<float> $retryBackOffSeconds seconds to wait before each retry of a request answered with 503 */
    public function __construct(
        private LoggerInterface $logger,
        private JsonEncoder $jsonEncoder,
        private PublicHttpClient $httpClient,
        private RateLimiterFactoryInterface $musicbrainzLimiter,
        private CoverArtArchiveAdapter $coverArtArchive,
        private MetadataProviderSwitches $switches,
        private array $retryBackOffSeconds = self::RETRY_BACK_OFF_SECONDS,
    ) {
    }

    /** @phpstan-impure */
    public function isEnabled(): bool
    {
        return $this->switches->enabled(MetadataProvider::MusicBrainz);
    }

    #[\Override]
    public function findReleases(
        string $title,
        ?string $artist,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled {
        return $this->search('/release', 'releases', self::fieldQuery(['release' => $title, 'artist' => $artist]), self::releaseSummary(...), $wait);
    }

    #[\Override]
    public function fetchRelease(
        string $releaseId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzRelease|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null {
        return $this->lookup('/release/' . rawurlencode($releaseId), self::RELEASE_INCLUDES, self::release(...), $wait);
    }

    #[\Override]
    public function findRecordings(
        string $title,
        ?string $artist,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled {
        return $this->search('/recording', 'recordings', self::fieldQuery(['recording' => $title, 'artist' => $artist]), self::recording(...), $wait);
    }

    #[\Override]
    public function fetchRecording(
        string $recordingId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null {
        return $this->lookup('/recording/' . rawurlencode($recordingId), self::RECORDING_INCLUDES, self::recording(...), $wait);
    }

    #[\Override]
    public function findArtists(
        string $name,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled {
        return $this->search('/artist', 'artists', self::fieldQuery(['artist' => $name]), self::artist(...), $wait);
    }

    #[\Override]
    public function fetchArtist(
        string $artistId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzArtist|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null {
        return $this->lookup('/artist/' . rawurlencode($artistId), 'aliases+url-rels', self::artist(...), $wait, reportsMerges: true);
    }

    #[\Override]
    public function fetchFrontCover(
        string $releaseId,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded,
    ): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null {
        if (!$this->isEnabled()) {
            return new MusicBrainzDisabled();
        }
        if (!$this->switches->enabled(MetadataProvider::CoverArtArchive)) {
            return new MusicBrainzDisabled('coverartarchive');
        }

        return $this->takeTurn($wait, self::deadline($wait)) ?? $this->coverArtArchive->fetchFrontCover($releaseId);
    }

    /**
     * Search artists by a free-text name query.
     *
     * The query is escaped, so Lucene syntax in it is searched as text. Returns an empty
     * result when MusicBrainz gives no usable answer.
     *
     * @throws ServiceUnavailableException when no turn under the shared rate limit comes within the wait
     */
    public function searchArtist(
        string $query,
        int $limit = 25,
        int $offset = 0,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Unbounded,
    ): MusicBrainzSearchResultDto {
        $data = $this->requestOrEmpty('/artist', ['query' => self::escape($query), 'limit' => $limit, 'offset' => $offset], $wait);

        return new MusicBrainzSearchResultDto(
            artists: array_map($this->artistDto(...), self::objects($data['artists'] ?? null)),
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Search release groups by a free-text title query and, when given, by artist credit.
     *
     * @throws ServiceUnavailableException when no turn under the shared rate limit comes within the wait
     */
    public function searchReleaseGroup(
        string $query,
        ?string $artist = null,
        int $limit = 25,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Unbounded,
    ): MusicBrainzSearchResultDto {
        $data = $this->requestOrEmpty('/release-group', ['query' => self::textQuery($query, $artist), 'limit' => $limit], $wait);

        return new MusicBrainzSearchResultDto(
            releaseGroups: array_map($this->releaseGroupDto(...), self::objects($data['release-groups'] ?? null)),
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Search recordings by a free-text title query and, when given, by artist credit.
     *
     * @throws ServiceUnavailableException when no turn under the shared rate limit comes within the wait
     */
    public function searchRecording(
        string $query,
        ?string $artist = null,
        int $limit = 25,
        MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Unbounded,
    ): MusicBrainzSearchResultDto {
        $data = $this->requestOrEmpty('/recording', ['query' => self::textQuery($query, $artist), 'limit' => $limit], $wait);

        return new MusicBrainzSearchResultDto(
            recordings: array_map($this->recordingDto(...), self::objects($data['recordings'] ?? null)),
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Look up a single artist by MBID with relationships, URLs, and tags.
     *
     * @throws ServiceUnavailableException when no turn under the shared rate limit comes within the wait
     */
    public function lookupArtist(string $mbid, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Unbounded): ?MusicBrainzArtistDto
    {
        $data = $this->requestOrEmpty('/artist/' . rawurlencode($mbid), ['inc' => 'url-rels+tags+artist-rels'], $wait);

        return isset($data['id']) ? $this->artistDto([...$data, 'score' => 0]) : null;
    }

    /**
     * Look up a single release group by MBID with releases, artist credits, and tags.
     *
     * @throws ServiceUnavailableException when no turn under the shared rate limit comes within the wait
     */
    public function lookupReleaseGroup(string $mbid, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Unbounded): ?MusicBrainzReleaseGroupDto
    {
        $data = $this->requestOrEmpty('/release-group/' . rawurlencode($mbid), ['inc' => 'releases+artist-credits+tags'], $wait);

        return isset($data['id']) ? $this->releaseGroupDto([...$data, 'score' => 0]) : null;
    }

    /**
     * @template T
     *
     * @param \Closure(array<array-key, mixed>): T $map
     *
     * @return list<T>|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
     */
    private function search(string $endpoint, string $hits, string $query, \Closure $map, MusicBrainzSlotWait $wait): array|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        $data = $this->fetch($endpoint, ['query' => $query, 'limit' => self::SEARCH_LIMIT], $wait);

        return match (true) {
            // A search has no record to miss, so a 404 is no empty result.
            $data === null => $this->rejected($endpoint, 404),
            is_array($data) => array_map($map, self::entries($data[$hits] ?? null)),
            default => $data,
        };
    }

    /**
     * @template T
     *
     * @param \Closure(array<array-key, mixed>): T $map
     * @param bool $reportsMerges whether a redirect to the lookup of another record of the same kind is
     *                            reported as a merge into that record; any other redirect is unavailable
     *
     * @return T|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
     */
    private function lookup(string $endpoint, ?string $includes, \Closure $map, MusicBrainzSlotWait $wait, bool $reportsMerges = false): mixed
    {
        $data = $this->fetch($endpoint, $includes === null ? [] : ['inc' => $includes], $wait, $reportsMerges);

        return match (true) {
            !is_array($data) => $data,
            !is_string($data['id'] ?? null) => $this->unavailable($endpoint, 'the response has no ID'),
            default => $map($data),
        };
    }

    /**
     * Take a turn under the shared limit, then GET a MusicBrainz endpoint and decode its JSON.
     *
     * @param array<string, string|int> $params query parameters besides the response format
     *
     * @return array<array-key, mixed>|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null null on 404
     */
    private function fetch(string $endpoint, array $params, MusicBrainzSlotWait $wait, bool $reportsMerges = false): array|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $deadline = self::deadline($wait);

        return $this->takeTurn($wait, $deadline) ?? $this->get($endpoint, $params, $wait, $deadline, $this->retryBackOffSeconds, $reportsMerges);
    }

    /**
     * A request for the search and lookup methods that answer with arrays: a turn under the shared
     * limit, then an empty array for any answer other than a decoded JSON object.
     *
     * @param array<string, string|int> $params
     *
     * @return array<array-key, mixed>
     *
     * @throws ServiceUnavailableException when no turn comes within the wait
     */
    private function requestOrEmpty(string $endpoint, array $params, MusicBrainzSlotWait $wait): array
    {
        $deadline = self::deadline($wait);
        $noTurn = $this->takeTurn($wait, $deadline);
        if ($noTurn instanceof MusicBrainzDisabled) {
            throw new MetadataProviderDisabled(MetadataProvider::MusicBrainz);
        }
        if ($noTurn !== null) {
            throw new ServiceUnavailableException('MusicBrainz cannot take another request now. Try again shortly.', ['reason' => $noTurn->reason]);
        }
        $data = $this->get($endpoint, $params, $wait, $deadline, $this->retryBackOffSeconds);

        if ($data instanceof MusicBrainzDisabled) {
            throw new MetadataProviderDisabled(MetadataProvider::MusicBrainz);
        }

        return is_array($data) ? $data : [];
    }

    /** When a bounded call stops waiting: its longest wait from now. Null when the wait has no limit. */
    private static function deadline(MusicBrainzSlotWait $wait): ?float
    {
        $seconds = $wait->maxSeconds();

        return $seconds === null ? null : microtime(true) + $seconds;
    }

    /**
     * Wait for this call's turn under the limit every process shares, answering unavailable at
     * once when the turn comes after the deadline. Sleeping yields the coroutine, so a web worker
     * keeps serving other requests meanwhile. No signal ends the wait early, so a queue worker
     * waits its full time although its keepalive alarm arrives.
     */
    private function takeTurn(MusicBrainzSlotWait $wait, ?float $deadline): MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        if (!$this->isEnabled()) {
            return new MusicBrainzDisabled();
        }
        $maxTime = $deadline === null ? null : max(0.0, $deadline - microtime(true));
        try {
            $reservation = $this->musicbrainzLimiter->create(self::LIMITER_KEY)->reserve(1, $maxTime);
        } catch (MaxWaitDurationExceededException) {
            return $this->isEnabled()
                ? $this->unavailable('rate limit', sprintf('no turn within %d seconds', $wait->maxSeconds()))
                : new MusicBrainzDisabled();
        } catch (RateLimiterUnavailableException) {
            return $this->isEnabled()
                ? $this->unavailable('rate limit', 'the shared rate limiter is unavailable')
                : new MusicBrainzDisabled();
        }
        Async::sleepThroughSignals($reservation->getWaitDuration());

        return null;
    }

    /**
     * GET a MusicBrainz endpoint once the call has its turn. A 503 is asked again after each
     * back-off, each time on a fresh turn, so retries keep the shared spacing too. A bounded call
     * answers unavailable at once when a back-off or a turn would end after its deadline.
     *
     * @param array<string, string|int> $params
     * @param list<float> $backOffs seconds to wait before each retry still allowed
     *
     * @return array<array-key, mixed>|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null null on 404
     */
    private function get(string $endpoint, array $params, MusicBrainzSlotWait $wait, ?float $deadline, array $backOffs, bool $reportsMerges = false): array|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|MusicBrainzDisabled|null
    {
        $response = $this->send($endpoint, $params, $reportsMerges);
        if (!is_array($response) || $response[0] !== self::SLOW_DOWN || $backOffs === []) {
            return is_array($response) ? $this->answer($endpoint, ...$response) : $response;
        }
        $backOff = array_shift($backOffs);
        $this->logger->info('MusicBrainz answered 503; asking again', ['service' => 'musicbrainz', 'endpoint' => $endpoint, 'backOffSeconds' => $backOff]);

        return $this->backOff($endpoint, $backOff, $deadline)
            ?? $this->takeTurn($wait, $deadline)
            ?? $this->get($endpoint, $params, $wait, $deadline, $backOffs, $reportsMerges);
    }

    /** Wait before a retry, or answer unavailable at once when the wait would end after the deadline. */
    private function backOff(string $endpoint, float $seconds, ?float $deadline): ?MusicBrainzUnavailable
    {
        if ($deadline !== null && microtime(true) + $seconds >= $deadline) {
            return $this->unavailable($endpoint, 'HTTP 503, and no time is left to ask again');
        }
        Async::sleepThroughSignals($seconds);

        return null;
    }

    /**
     * Send one GET. Destination validation and connection pinning are handled by the public
     * provider transport, which follows no redirect here. A redirect fails the request, unless
     * $returnRedirects asks for the redirect itself, with its Location.
     *
     * @param array<string, string|int> $params
     *
     * @return array{int, string, string|null}|MusicBrainzUnavailable|MusicBrainzDisabled the status, body and Location of a redirect
     */
    private function send(string $endpoint, array $params, bool $returnRedirects = false): array|MusicBrainzUnavailable|MusicBrainzDisabled
    {
        if (!$this->isEnabled()) {
            return new MusicBrainzDisabled();
        }
        $url = self::BASE_URL . $endpoint . '?' . http_build_query([...$params, 'fmt' => 'json'], encoding_type: PHP_QUERY_RFC3986);
        $response = null;
        try {
            $response = $this->httpClient->request('GET', $url, ['musicbrainz.org'], [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
            ], returnRedirects: $returnRedirects);
            $status = $response->getStatusCode();
            $location = $status >= 300 && $status < 400 ? ($response->getHeaders(false)['location'][0] ?? null) : null;

            return [$status, $this->httpClient->content($response), $location];
        } catch (\Throwable) {
            return $this->unavailable($endpoint, 'the request failed');
        } finally {
            $response?->cancel();
        }
    }

    /** @return array<array-key, mixed>|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|null null on 404 */
    private function answer(string $endpoint, int $status, string $body, ?string $location): array|MusicBrainzMerged|MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        return match (true) {
            $status === 200 => $this->decode($endpoint, $body),
            $status === self::MERGED && $location !== null => $this->merged($endpoint, $location),
            default => $this->errorAnswer($endpoint, $status),
        };
    }

    /**
     * A lookup MusicBrainz redirected permanently to the lookup of another record of the same kind:
     * it merged the requested record into that one. The redirect is never followed, only read, so
     * its scheme does not matter and a relative one counts. Any other target is no answer.
     */
    private function merged(string $endpoint, string $location): MusicBrainzMerged|MusicBrainzUnavailable
    {
        $target = UriResolver::resolve(new Uri(self::BASE_URL . $endpoint), new Uri($location));
        $lookup = '#^' . preg_quote(self::LOOKUP_PATH . dirname($endpoint) . '/', '#') . '(' . self::MBID_PATTERN . ')$#';
        $requestedId = basename($endpoint) |> rawurldecode(...);
        $onMusicBrainz = in_array($target->getScheme(), ['http', 'https'], true) && strtolower($target->getHost()) === self::HOST;

        return $onMusicBrainz && preg_match($lookup, $target->getPath(), $surviving) === 1 && $surviving[1] !== strtolower($requestedId)
            ? new MusicBrainzMerged($requestedId, $surviving[1])
            : $this->unavailable($endpoint, sprintf('HTTP %d to %s', self::MERGED, (string) $target));
    }

    /** @return array<array-key, mixed>|MusicBrainzUnavailable */
    private function decode(string $endpoint, string $body): array|MusicBrainzUnavailable
    {
        try {
            $data = $this->jsonEncoder->decode($body, 'json');
        } catch (NotEncodableValueException) {
            return $this->unavailable($endpoint, 'the response is not JSON');
        }

        return is_array($data) ? $data : $this->unavailable($endpoint, 'the response is not a JSON object');
    }

    /** What an error status means: no record, a refused request, or MusicBrainz being unavailable. */
    private function errorAnswer(string $endpoint, int $status): MusicBrainzRejected|MusicBrainzUnavailable|null
    {
        return match (true) {
            $status === 404 => null,
            $status >= 400 && $status < 500 && !in_array($status, self::NOT_REJECTIONS, true) => $this->rejected($endpoint, $status),
            default => $this->unavailable($endpoint, sprintf('HTTP %d', $status)),
        };
    }

    private function rejected(string $endpoint, int $status): MusicBrainzRejected
    {
        $this->logger->warning('MusicBrainz rejected the request', ['service' => 'musicbrainz', 'endpoint' => $endpoint, 'status' => $status]);

        return new MusicBrainzRejected($status);
    }

    private function unavailable(string $endpoint, string $reason): MusicBrainzUnavailable
    {
        $this->logger->warning('MusicBrainz request failed', ['service' => 'musicbrainz', 'endpoint' => $endpoint, 'error' => $reason]);

        return new MusicBrainzUnavailable($reason);
    }

    /**
     * Quoted field phrases joined by AND, leaving out fields without a value.
     *
     * @param array<string, string|null> $fields
     */
    private static function fieldQuery(array $fields): string
    {
        $present = array_filter($fields, static fn(?string $value): bool => $value !== null && trim($value) !== '');

        return array_map(self::phrase(...), array_keys($present), $present)
            |> (static fn(array $phrases): string => implode(' AND ', $phrases));
    }

    private static function phrase(string $field, string $value): string
    {
        return sprintf('%s:"%s"', $field, trim($value) |> self::escape(...));
    }

    /** Free text searched in the endpoint's default field, plus a quoted artist phrase when one is given. */
    private static function textQuery(string $text, ?string $artist): string
    {
        return $artist === null || trim($artist) === ''
            ? self::escape($text)
            : sprintf('(%s) AND %s', self::escape($text), self::phrase('artist', $artist));
    }

    private static function escape(string $text): string
    {
        return preg_replace(self::LUCENE_SPECIALS, '\\\\$0', $text) ?? $text;
    }

    /** @param array<array-key, mixed> $release */
    private static function releaseSummary(array $release): MusicBrainzReleaseSummary
    {
        $media = array_map(
            static fn(array $medium): MusicBrainzMediumSummary => new MusicBrainzMediumSummary(self::text($medium, 'format'), self::integer($medium, 'track-count') ?? 0),
            self::objects($release['media'] ?? null),
        );

        return new MusicBrainzReleaseSummary(
            id: $release['id'],
            title: self::text($release, 'title') ?? '',
            score: self::integer($release, 'score') ?? 0,
            artistCredit: self::artistCredit($release['artist-credit'] ?? null),
            date: self::text($release, 'date'),
            country: self::text($release, 'country'),
            status: self::text($release, 'status'),
            disambiguation: self::text($release, 'disambiguation'),
            barcode: self::text($release, 'barcode'),
            labels: self::labels($release['label-info'] ?? null),
            media: $media,
            trackCount: self::integer($release, 'track-count') ?? (array_column($media, 'trackCount') |> array_sum(...)),
            releaseGroup: self::releaseGroup($release['release-group'] ?? null),
        );
    }

    /** @param array<array-key, mixed> $release */
    private static function release(array $release): MusicBrainzRelease
    {
        $media = array_map(self::medium(...), self::objects($release['media'] ?? null));
        usort($media, static fn(MusicBrainzMedium $a, MusicBrainzMedium $b): int => $a->position <=> $b->position);

        return new MusicBrainzRelease(
            id: $release['id'],
            title: self::text($release, 'title') ?? '',
            artistCredit: self::artistCredit($release['artist-credit'] ?? null),
            date: self::text($release, 'date'),
            country: self::text($release, 'country'),
            status: self::text($release, 'status'),
            disambiguation: self::text($release, 'disambiguation'),
            barcode: self::text($release, 'barcode'),
            labels: self::labels($release['label-info'] ?? null),
            media: $media,
            releaseGroup: self::releaseGroup($release['release-group'] ?? null),
            genres: self::genres($release['genres'] ?? null),
            hasFrontCover: ($release['cover-art-archive']['front'] ?? false) === true,
        );
    }

    /** @param array<array-key, mixed> $medium */
    private static function medium(array $medium): MusicBrainzMedium
    {
        $tracks = array_filter(
            self::entries($medium['tracks'] ?? null),
            static fn(array $track): bool => is_string($track['recording']['id'] ?? null),
        ) |> (static fn(array $tracks): array => array_map(self::track(...), $tracks));
        usort($tracks, static fn(MusicBrainzTrack $a, MusicBrainzTrack $b): int => $a->position <=> $b->position);

        return new MusicBrainzMedium(
            position: self::integer($medium, 'position') ?? 0,
            format: self::text($medium, 'format'),
            title: self::text($medium, 'title'),
            tracks: $tracks,
        );
    }

    /** @param array<array-key, mixed> $track */
    private static function track(array $track): MusicBrainzTrack
    {
        $recording = $track['recording'];

        return new MusicBrainzTrack(
            id: $track['id'],
            position: self::integer($track, 'position') ?? 0,
            number: self::text($track, 'number') ?? '',
            title: self::text($track, 'title') ?? '',
            lengthMs: self::integer($track, 'length') ?? self::integer($recording, 'length'),
            recordingId: $recording['id'],
            artistCredit: self::artistCredit($track['artist-credit'] ?? $recording['artist-credit'] ?? null),
        );
    }

    /** @param array<array-key, mixed> $recording */
    private static function recording(array $recording): MusicBrainzRecording
    {
        return new MusicBrainzRecording(
            id: $recording['id'],
            title: self::text($recording, 'title') ?? '',
            score: self::integer($recording, 'score') ?? 0,
            lengthMs: self::integer($recording, 'length'),
            disambiguation: self::text($recording, 'disambiguation'),
            artistCredit: self::artistCredit($recording['artist-credit'] ?? null),
            releases: array_map(self::recordingRelease(...), self::entries($recording['releases'] ?? null)),
        );
    }

    /** @param array<array-key, mixed> $release */
    private static function recordingRelease(array $release): MusicBrainzRecordingRelease
    {
        return new MusicBrainzRecordingRelease(
            id: $release['id'],
            title: self::text($release, 'title') ?? '',
            date: self::text($release, 'date'),
            country: self::text($release, 'country'),
            status: self::text($release, 'status'),
            trackCount: self::integer($release, 'track-count'),
            releaseGroup: self::releaseGroup($release['release-group'] ?? null),
        );
    }

    /** @param array<array-key, mixed> $artist */
    private static function artist(array $artist): MusicBrainzArtist
    {
        $lifeSpan = is_array($artist['life-span'] ?? null) ? $artist['life-span'] : [];

        return new MusicBrainzArtist(
            id: $artist['id'],
            name: self::text($artist, 'name') ?? '',
            score: self::integer($artist, 'score') ?? 0,
            sortName: self::text($artist, 'sort-name'),
            type: self::text($artist, 'type'),
            country: self::text($artist, 'country'),
            disambiguation: self::text($artist, 'disambiguation'),
            lifeSpanBegin: self::text($lifeSpan, 'begin'),
            lifeSpanEnd: self::text($lifeSpan, 'end'),
            ended: ($lifeSpan['ended'] ?? false) === true,
            gender: self::text($artist, 'gender'),
            birthplace: is_array($artist['begin-area'] ?? null) ? self::text($artist['begin-area'], 'name') : null,
            aliases: array_map(
                static fn (array $alias): ?string => self::text($alias, 'name'),
                self::objects($artist['aliases'] ?? null),
            ) |> array_filter(...) |> array_values(...),
            personIdentifiers: MusicBrainzArtistLinks::fromRelationships(self::objects($artist['relations'] ?? null)),
        );
    }

    private static function releaseGroup(mixed $group): ?MusicBrainzReleaseGroup
    {
        if (!is_array($group) || !is_string($group['id'] ?? null)) {
            return null;
        }

        return new MusicBrainzReleaseGroup(
            id: $group['id'],
            title: self::text($group, 'title') ?? '',
            primaryType: self::text($group, 'primary-type'),
            firstReleaseDate: self::text($group, 'first-release-date'),
            genres: self::genres($group['genres'] ?? null),
        );
    }

    /** An ordered credit of the entries naming an artist; MusicBrainz leaves out an empty join phrase in search results. */
    private static function artistCredit(mixed $credit): MusicBrainzArtistCredit
    {
        return array_filter(self::objects($credit), static fn(array $entry): bool => is_string($entry['artist']['id'] ?? null))
            |> (static fn(array $entries): array => array_map(self::creditedArtist(...), array_values($entries)))
            |> (static fn(array $artists): MusicBrainzArtistCredit => new MusicBrainzArtistCredit($artists));
    }

    /** @param array<array-key, mixed> $entry */
    private static function creditedArtist(array $entry): MusicBrainzCreditedArtist
    {
        $artist = $entry['artist'];
        $artistName = self::text($artist, 'name') ?? '';

        return new MusicBrainzCreditedArtist(
            artistId: $artist['id'],
            name: self::text($entry, 'name') ?? $artistName,
            joinPhrase: is_string($entry['joinphrase'] ?? null) ? $entry['joinphrase'] : '',
            artistName: $artistName,
            artistSortName: self::text($artist, 'sort-name'),
        );
    }

    /** @return list<MusicBrainzLabel> */
    private static function labels(mixed $labelInfo): array
    {
        return array_map(static function (array $info): MusicBrainzLabel {
            $label = is_array($info['label'] ?? null) ? $info['label'] : [];

            return new MusicBrainzLabel(self::text($label, 'id'), self::text($label, 'name'), self::text($info, 'catalog-number'));
        }, self::objects($labelInfo));
    }

    /** @return list<string> genre names, most votes first; MusicBrainz's own order breaks ties */
    private static function genres(mixed $genres): array
    {
        $named = array_filter(self::objects($genres), static fn(array $genre): bool => is_string($genre['name'] ?? null));
        usort($named, static fn(array $a, array $b): int => (self::integer($b, 'count') ?? 0) <=> (self::integer($a, 'count') ?? 0));

        return array_column($named, 'name');
    }

    /** @return list<array<array-key, mixed>> the entries of a JSON list that are objects */
    private static function objects(mixed $list): array
    {
        return is_array($list) ? (array_filter($list, is_array(...)) |> array_values(...)) : [];
    }

    /** @return list<array<array-key, mixed>> the entries of a JSON list that are objects with an ID */
    private static function entries(mixed $list): array
    {
        return array_filter(self::objects($list), static fn(array $entry): bool => is_string($entry['id'] ?? null))
            |> array_values(...);
    }

    /**
     * A non-empty string value, else null.
     *
     * @param array<array-key, mixed> $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<array-key, mixed> $data */
    private static function integer(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $artist */
    private function artistDto(array $artist): MusicBrainzArtistDto
    {
        return new MusicBrainzArtistDto(
            id: $artist['id'],
            name: $artist['name'],
            sortName: $artist['sort-name'] ?? null,
            type: $artist['type'] ?? null,
            country: $artist['country'] ?? null,
            disambiguation: $artist['disambiguation'] ?? null,
            lifeSpanBegin: $artist['life-span']['begin'] ?? null,
            lifeSpanEnd: $artist['life-span']['end'] ?? null,
            tags: $this->extractTags($artist['tags'] ?? []),
            score: $artist['score'] ?? 0,
        );
    }

    /** @param array<array-key, mixed> $group */
    private function releaseGroupDto(array $group): MusicBrainzReleaseGroupDto
    {
        return new MusicBrainzReleaseGroupDto(
            id: $group['id'],
            title: $group['title'],
            primaryType: $group['primary-type'] ?? null,
            secondaryTypes: $group['secondary-types'] ?? [],
            firstReleaseDate: $group['first-release-date'] ?? null,
            artistCredit: $this->flattenArtistCredit($group['artist-credit'] ?? []),
            tags: $this->extractTags($group['tags'] ?? []),
            score: $group['score'] ?? 0,
        );
    }

    /**
     * Only the first release of each recording is kept; a recording usually appears on many.
     *
     * @param array<array-key, mixed> $recording
     */
    private function recordingDto(array $recording): MusicBrainzRecordingDto
    {
        $firstRelease = $recording['releases'][0] ?? null;

        return new MusicBrainzRecordingDto(
            id: $recording['id'],
            title: $recording['title'],
            length: $recording['length'] ?? null,
            artistCredit: $this->flattenArtistCredit($recording['artist-credit'] ?? []),
            releaseId: $firstRelease['id'] ?? null,
            releaseTitle: $firstRelease['title'] ?? null,
            trackNumber: null,
            tags: $this->extractTags($recording['tags'] ?? []),
            score: $recording['score'] ?? 0,
        );
    }

    /**
     * Extract tag names from the MusicBrainz tag array.
     *
     * @param array<array-key, array<string, mixed>> $tags
     * @return string[]
     */
    private function extractTags(array $tags): array
    {
        return array_column($tags, 'name')
            |> (static fn(array $names): array => array_filter($names, is_string(...)))
            |> array_values(...);
    }

    /**
     * Flatten a MusicBrainz artist-credit array into a display string of artist names and join phrases.
     *
     * @param array<array-key, array<string, mixed>> $artistCredit
     */
    private function flattenArtistCredit(array $artistCredit): string
    {
        return array_map(
            static fn(array $credit): string => ($credit['artist']['name'] ?? $credit['name'] ?? '') . ($credit['joinphrase'] ?? ''),
            $artistCredit,
        ) |> implode(...);
    }
}
