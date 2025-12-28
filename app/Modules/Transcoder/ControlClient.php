<?php

namespace App\Modules\Transcoder;

use App\Modules\Transcoder\Dto\HealthStatus;
use App\Modules\Transcoder\Dto\ServerStats;
use App\Modules\Transcoder\Dto\SessionInfo;
use App\Modules\Transcoder\Dto\TranscodeJob;
use App\Modules\Transcoder\Dto\TranscodeStatus;
use App\Modules\Transcoder\Dto\VideoMetadata;
use App\Modules\Transcoder\Exception\ResponseException;
use App\Modules\Transcoder\Exception\SocketException;
use App\Modules\Transcoder\Logging\LoggerInterface;
use App\Modules\Transcoder\Logging\NullLogger;
use App\Modules\Transcoder\Protocol\HttpMethod;
use App\Modules\Transcoder\Protocol\HttpRequest;
use App\Modules\Transcoder\Protocol\HttpResponse;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Transcoder Control Client
 *
 * PHP-side control client for managing transcoding operations.
 * Does NOT handle media delivery
 *
 * Typical usage:
 * - Start/stop transcode jobs
 * - Get HLS/DASH playlists (URLs returned point to Node.js)
 * - Query status and metadata
 * - Control playback (skip, quality change)
 * - Manage sessions
 *
 * Connection Pooling:
 * For high-concurrency scenarios, use withConnectionPool() to enable connection
 * reuse and reduce connection overhead.
 *
 * Logging:
 * Pass a logger implementation for debugging and tracing.
 */
class ControlClient
{
    private ?ConnectionPool $pool = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string          $socketPath = '/tmp/transcoder.sock',
        private readonly float           $timeout = 30.0,
        private readonly int             $maxMessageSize = 104857600,
        private readonly array           $options = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
    }

    /**
     * Enable connection pooling
     *
     * @param array<string, mixed> $poolOptions
     * @return $this
     */
    public function withConnectionPool(
        int   $maxPoolSize = 10,
        float $idleTimeout = 60.0,
        array $poolOptions = [],
    ): self
    {
        $this->pool = new ConnectionPool(
            $this->socketPath,
            $this->timeout,
            $this->maxMessageSize,
            $maxPoolSize,
            $idleTimeout,
            array_merge($this->options, $poolOptions),
            $this->logger,
        );

        $this->logger->info('Connection pooling enabled', [
            'maxPoolSize' => $maxPoolSize,
            'idleTimeout' => $idleTimeout,
        ]);

        return $this;
    }

    /**
     * Get connection pool statistics (only available when pooling is enabled)
     *
     * @return array<string, mixed>|null
     */
    public function getPoolStats(): ?array
    {
        return $this->pool?->getStats();
    }

    /**
     * Get server statistics
     *
     * @throws SocketException
     */
    public function getStats(): ServerStats
    {
        return $this->withConnection(function (SocketClient $client) {
            $request = HttpRequest::get('/api/monitor', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $data = $response->getBodyJsonArray() ?? [];

            // Map /api/monitor response to ServerStats
            return ServerStats::fromArray($this->mapMonitorToStats($data));
        });
    }

    /**
     * Execute callback with a connection (uses pool if available)
     *
     * @template T
     * @param Closure(SocketClient): T $callback
     * @return T
     * @throws SocketException
     */
    private function withConnection(Closure $callback): mixed
    {
        if ($this->pool !== null) {
            return $this->pool->withConnection($callback);
        }

        return $this->withConnection($callback);
    }

    // ========================================================================
    // Health & Status
    // ========================================================================

    /**
     * Map /api/monitor response to ServerStats format
     *
     * @param array<string, mixed> $monitorData
     * @return array<string, mixed>
     */
    private function mapMonitorToStats(array $monitorData): array
    {
        $system = $monitorData['system'] ?? [];
        $process = $monitorData['process'] ?? [];
        $transcoding = $monitorData['transcoding'] ?? [];

        return [
            'activeSessions'       => $transcoding['activeSessionsCount'] ?? 0,
            'totalSessionsCreated' => 0, // Not tracked in monitor endpoint
            'activeTranscoders'    => 0, // Not exposed in monitor endpoint
            'completedTranscodes'  => 0, // Not tracked in monitor endpoint
            'failedTranscodes'     => 0, // Not tracked in monitor endpoint
            'cpuUsage'             => 0.0, // Not exposed in monitor endpoint
            'memoryUsed'           => $process['memory']['rss'] ?? 0,
            'memoryTotal'          => $system['memory']['total'] ?? 0,
            'uptimeSeconds'        => (int)($process['uptime'] ?? 0),
            'additionalData'       => [
                'systemMemory' => $system['memory'] ?? [],
                'loadAverage'  => $system['loadAverage'] ?? [],
                'sessions'     => $transcoding['sessions'] ?? [],
            ],
        ];
    }

    /**
     * Close all pooled connections
     */
    public function closePool(): void
    {
        $this->pool?->closeAll();
        $this->pool = null;
    }

    /**
     * Check server health
     *
     * @throws SocketException
     */
    public function getHealth(): HealthStatus
    {
        $this->logger->debug('Sending health check request');

        return $this->withConnection(function (SocketClient $client) {
            $request = HttpRequest::get('/api/health', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $data = $response->getBodyJsonArray() ?? [];

            $health = HealthStatus::fromArray($data);

            $this->logger->info('Health check completed', [
                'status'  => $health->status,
                'healthy' => $health->isHealthy(),
                'version' => $health->version,
            ]);

            return $health;
        });
    }

    /**
     * Ping the server
     *
     * @throws SocketException
     */
    public function ping(): bool
    {
        return $this->withConnection(
            fn(SocketClient $client) => $client->ping(),
        );
    }

    /**
     * Get video metadata
     *
     * @throws SocketException
     */
    public function getVideoMetadata(string $videoId): VideoMetadata
    {
        return $this->withConnection(function (SocketClient $client) use ($videoId) {
            $request = HttpRequest::get("/api/video/$videoId/metadata", [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            return VideoMetadata::fromArray($data);
        });
    }

    // ========================================================================
    // Video Metadata
    // ========================================================================

    /**
     * Ensure response is successful
     */
    private function ensureSuccess(HttpResponse $response): void
    {
        if (!$response->isSuccess()) {
            $this->logger->error('HTTP request failed', [
                'statusCode'    => $response->getStatusCode(),
                'statusMessage' => $response->getStatusMessage(),
                'body'          => substr($response->getBodyString(), 0, 500),
            ]);

            throw ResponseException::fromStatus(
                $response->getStatusCode(),
                $response->getStatusMessage(),
                substr($response->getBodyString(), 0, 1000),
            );
        }
    }

    /**
     * List available videos
     *
     * @return array<string, mixed> List of videos
     * @throws SocketException
     */
    public function listVideos(): array
    {
        return $this->withConnection(function (SocketClient $client) {
            $request = HttpRequest::get('/api/videos', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            return $response->getBodyJsonArray() ?? [];
        });
    }

    /**
     * Check if video exists
     *
     * @throws SocketException
     */
    public function videoExists(string $videoId): bool
    {
        return $this->withConnection(function (SocketClient $client) use ($videoId) {
            $request = HttpRequest::head("/api/video/{$videoId}");

            $response = $client->sendRequest($request);
            return $response->getStatusCode() === 200;
        });
    }

    // ========================================================================
    // Transcode Control
    // ========================================================================

    /**
     * Start a transcode job
     *
     * @param array<string, mixed> $options Transcode options (quality, format, etc.)
     * @throws SocketException
     */
    public function startTranscode(string $videoId, array $options = []): TranscodeJob
    {
        $this->logger->info('Starting transcode', [
            'videoId' => $videoId,
            'options' => $options,
        ]);

        return $this->withConnection(function (SocketClient $client) use ($videoId, $options) {
            $body = json_encode(array_merge(['videoId' => $videoId], $options));

            $request = HttpRequest::post('/api/transcode/start', $body, [
                'content-type' => 'application/json',
                'accept'       => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            $job = TranscodeJob::fromArray($data);

            $this->logger->info('Transcode started', [
                'jobId'     => $job->jobId,
                'videoId'   => $videoId,
                'status'    => $job->status,
                'isRunning' => $job->isRunning(),
            ]);

            return $job;
        });
    }

    /**
     * Stop a transcode job
     *
     * @throws SocketException
     */
    public function stopTranscode(string $jobId): TranscodeJob
    {
        return $this->withConnection(function (SocketClient $client) use ($jobId) {
            $request = HttpRequest::post("/api/transcode/{$jobId}/stop", '', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            return TranscodeJob::fromArray($data);
        });
    }

    /**
     * Skip to a specific time
     *
     * @param int $seconds Time in seconds
     * @throws SocketException
     */
    public function skipTo(string $jobId, int $seconds): TranscodeStatus
    {
        return $this->withConnection(function (SocketClient $client) use ($jobId, $seconds) {
            $body = json_encode(['time' => $seconds]);

            $request = HttpRequest::post("/api/transcode/{$jobId}/skip", $body, [
                'content-type' => 'application/json',
                'accept'       => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            return TranscodeStatus::fromArray($data);
        });
    }

    /**
     * Get transcode job status
     *
     * @throws SocketException
     */
    public function getTranscodeStatus(string $jobId): TranscodeStatus
    {
        return $this->withConnection(function (SocketClient $client) use ($jobId) {
            $request = HttpRequest::get("/api/transcode/{$jobId}/status", [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            return TranscodeStatus::fromArray($data);
        });
    }

    /**
     * List active transcode jobs
     *
     * @return array<int, TranscodeJob> List of jobs
     * @throws SocketException
     */
    public function listTranscodes(): array
    {
        return $this->withConnection(function (SocketClient $client) {
            $request = HttpRequest::get('/api/transcode/list', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray() ?? [];
            $jobs = [];

            foreach ($data as $jobData) {
                if (is_array($jobData)) {
                    $jobs[] = TranscodeJob::fromArray($jobData);
                }
            }

            return $jobs;
        });
    }

    // ========================================================================
    // HLS Control
    // ========================================================================

    /**
     * Get HLS master playlist
     *
     * Returns the playlist content with URLs pointing to the Node.js server.
     * Browser will fetch segments directly from Node.js, not through PHP.
     *
     * @return string M3U8 playlist content
     * @throws SocketException
     */
    public function getHlsMasterPlaylist(string $videoId): string
    {
        return $this->withConnection(function (SocketClient $client) use ($videoId) {
            $request = HttpRequest::get("/api/hls/master/{$videoId}.m3u8", [
                'accept' => 'application/vnd.apple.mpegurl',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            return $response->getBodyString();
        });
    }

    /**
     * Get HLS media playlist for a specific quality
     *
     * Returns the playlist with segment URLs pointing to Node.js.
     *
     * @return string M3U8 playlist content
     * @throws SocketException
     */
    public function getHlsMediaPlaylist(string $videoId, string $quality = '1080p'): string
    {
        return $this->withConnection(function (SocketClient $client) use ($videoId, $quality) {
            $request = HttpRequest::get("/api/hls/playlist/{$videoId}/{$quality}.m3u8", [
                'accept' => 'application/vnd.apple.mpegurl',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            return $response->getBodyString();
        });
    }

    /**
     * Get HLS segment URL (not the data, just the URL)
     *
     * Returns the URL that the browser should use to fetch the segment.
     *
     * @return string URL to the segment
     */
    public function getHlsSegmentUrl(string $videoId, string $quality, int $sequence): string
    {
        // The PHP app returns a URL that points to the Node.js server
        // The browser will fetch the segment directly from Node.js
        return $this->getNodeBaseUrl() . "/api/hls/segment/{$videoId}/{$quality}/{$sequence}.ts";
    }

    // ========================================================================
    // DASH Control
    // ========================================================================

    /**
     * Get the base URL for the Node.js server
     *
     * This is what browsers will connect to for actual media delivery.
     * May be HTTP/HTTPS depending on your Node.js configuration.
     */
    private function getNodeBaseUrl(): string
    {
        // You can configure this via environment variable or config
        // Default assumes Node.js is listening on localhost:3000
        return getenv('NODE_BASE_URL') ?: 'http://localhost:3000';
    }

    /**
     * Get DASH manifest
     *
     * Returns the MPD with segment URLs pointing to Node.js.
     *
     * @return string MPD manifest content
     * @throws SocketException
     */
    public function getDashManifest(string $videoId): string
    {
        return $this->withConnection(function (SocketClient $client) use ($videoId) {
            $request = HttpRequest::get("/api/dash/manifest/{$videoId}.mpd", [
                'accept' => 'application/dash+xml',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            return $response->getBodyString();
        });
    }

    // ========================================================================
    // URL Generation
    // ========================================================================

    /**
     * Get DASH segment URL (not the data, just the URL)
     *
     * @return string URL to the segment
     */
    public function getDashSegmentUrl(string $videoId, string $quality, int $segment): string
    {
        return $this->getNodeBaseUrl() . "/api/dash/segment/{$videoId}/{$quality}/{$segment}.m4s";
    }

    /**
     * Get the complete stream URL for a video
     *
     * Returns the URL that should be passed to the video player.
     *
     * @param 'hls'|'dash' $format Streaming format
     * @throws SocketException
     */
    public function getStreamUrl(string $videoId, string $format = 'hls'): string
    {
        $baseUrl = $this->getNodeBaseUrl();

        return match ($format) {
            'hls' => $baseUrl . "/api/hls/master/{$videoId}.m3u8",
            'dash' => $baseUrl . "/api/dash/manifest/{$videoId}.mpd",
            default => throw new InvalidArgumentException("Invalid format: {$format}"),
        };
    }

    // ========================================================================
    // Session Control
    // ========================================================================

    /**
     * Get active sessions for a specific video
     *
     * @return array<int, SessionInfo> List of active sessions for the video
     * @throws SocketException
     */
    public function getActiveSessionsForVideo(string $videoId): array
    {
        $allSessions = $this->getActiveSessions();

        // Filter sessions by video ID (input source)
        return array_filter($allSessions, function (SessionInfo $session) use ($videoId) {
            // Session input source contains videoId
            return str_contains($session->id, $videoId);
        });
    }

    /**
     * Get all active sessions
     *
     * @return array<int, SessionInfo> List of active sessions
     * @throws SocketException
     */
    public function getActiveSessions(): array
    {
        return $this->withConnection(function (SocketClient $client) {
            $request = HttpRequest::get('/api/monitor', [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);
            $this->ensureSuccess($response);

            $data = $response->getBodyJsonArray();
            $sessionData = $data['transcoding']['sessions'] ?? [];

            $sessions = [];
            foreach ($sessionData as $s) {
                $sessions[] = SessionInfo::fromArray([
                    'id'           => $s['sessionId'] ?? '',
                    'startSegment' => 0,
                    'variantId'    => $s['height'] ? $s['height'] . 'p' : 'original',
                    'height'       => $s['height'] ?? 0,
                    'format'       => $s['format'] ?? 'hls',
                    'lastUsed'     => isset($s['startTime']) ? strtotime($s['startTime']) : 0,
                    'isPaused'     => false,
                    'maxSegment'   => $s['segmentsGenerated'] ?? 0,
                    'userCount'    => 0,
                    'createdAt'    => isset($s['startTime']) ? strtotime($s['startTime']) : 0,
                ]);
            }

            return $sessions;
        });
    }

    /**
     * Get detailed session info by session ID
     *
     * Note: This returns session info from the monitor endpoint.
     * For detailed session data, the API needs to be extended.
     *
     * @throws SocketException
     */
    public function getSessionInfo(string $sessionId): ?SessionInfo
    {
        $sessions = $this->getActiveSessions();

        return array_find($sessions, fn($session) => $session->id === $sessionId);
    }

    /**
     * Terminate a specific session
     *
     * Note: This endpoint may not be fully implemented on the server yet.
     * The method is provided for future use.
     *
     * @return array<string, mixed> Result
     * @throws SocketException
     */
    public function terminateSession(string $sessionId): array
    {
        return $this->withConnection(function (SocketClient $client) use ($sessionId) {
            $request = HttpRequest::delete("/api/sessions/{$sessionId}", [
                'accept' => 'application/json',
            ]);

            $response = $client->sendRequest($request);

            // Don't throw error for 404 - session might already be terminated
            if ($response->getStatusCode() === 404) {
                return ['success' => true, 'message' => 'Session not found (already terminated)'];
            }

            $this->ensureSuccess($response);

            return $response->getBodyJsonArray() ?? [];
        });
    }

    // ========================================================================
    // Utility
    // ========================================================================

    /**
     * Execute a raw control request
     *
     * @param array<string, string> $headers
     * @throws SocketException
     */
    public function request(
        HttpMethod|string $method,
        string            $path,
        array             $headers = [],
        ?string           $body = null,
    ): HttpResponse
    {
        return $this->withConnection(
            function (SocketClient $client) use ($method, $path, $headers, $body) {
                $request = HttpRequest::create($method, $path, $headers, $body);
                return $client->sendRequest($request);
            },
        );
    }

    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize ControlClient');
    }

    /**
     * Get the underlying client (creates new instance)
     */
    private function getClient(): SocketClient
    {
        return new SocketClient(
            $this->socketPath,
            $this->timeout,
            $this->maxMessageSize,
            options: $this->options,
        );
    }

    private function __clone()
    {
    }
}
