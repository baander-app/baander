# Transcoder Socket Control Client

Control client for the Node.js Transcoder Service using Unix domain sockets.

**Features:**

- Type-safe DTOs for all responses
- **Connection pooling** for high-concurrency scenarios
- **Request ID tracking** for debugging and tracing
- **Structured logging** with PSR-3 support

## Architecture

```
┌─────────────┐  control requests (playlists, start/stop)  ┌──────────────────────┐
│   PHP       │ ──────────────────────────────────────────>│   Node.js Server     │
│  (Control)  │   - playlists, metadata, status            │                      │
│             │ <──────────────────────────────────────────│   (Media Server)     │
└─────────────┘  returns stream URLs                       │                      │
          ^                                                │                      │
          │                                                │                      │
          │   stream URL (returned by PHP)                 │                      │
┌─────────┴──────────┐  direct HTTP connection (media)     │                      │
│   Browser/Player   │ ───────────────────────────────────>│                      │
│                    │    fetches .ts and .m4s segments    │                      │
└────────────────────┘    (high bandwidth video)           └──────────────────────┘
```

**PHP handles control** - start/stop transcode, get playlists, query status
**Browsers fetch media directly** - segments served by Node.js for performance

## Quick Start

```php
<?php

use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Dto\TranscodeJob;
use App\Modules\Transcoder\Dto\VideoMetadata;

$client = new ControlClient('/tmp/transcoder.sock');

// Start a transcode job - returns TranscodeJob DTO
$job = $client->startTranscode('video123', [
    'qualities' => ['1080p', '720p', '480p'],
    'format' => 'hls',
]);

echo "Job ID: {$job->jobId}\n";
echo "Running: " . ($job->isRunning() ? 'Yes' : 'No') . "\n";

// Get metadata - returns VideoMetadata DTO
$metadata = $client->getVideoMetadata('video123');
echo "Duration: {$metadata->getDurationFormatted()}\n";
echo "Resolution: {$metadata->getResolution()}\n";

// Get the stream URL to pass to your video player
$streamUrl = $client->getStreamUrl('video123', 'hls');
// Returns: "http://localhost:3000/api/hls/master/video123.m3u8"
```

## Available DTOs

All API responses return type-safe DTOs:

| DTO               | Description                              |
|-------------------|------------------------------------------|
| `HealthStatus`    | Server health information                |
| `ServerStats`     | Server statistics with memory/CPU info   |
| `VideoMetadata`   | Video metadata with helper methods       |
| `TranscodeJob`    | Transcode job information                |
| `TranscodeStatus` | Real-time transcode status with progress |
| `SessionInfo`     | Session details with activity tracking   |
| `ClientInfo`      | Client information for sessions          |
| `UserRequest`     | User request tracking data               |

## API Reference

### Health & Status

```php
use App\Modules\Transcoder\Dto\HealthStatus;
use App\Modules\Transcoder\Dto\ServerStats;

// Check server health - returns HealthStatus DTO
$health = $client->getHealth();
if ($health->isHealthy()) {
    echo "Server is healthy (v{$health->version})\n";
}

// Get server statistics - returns ServerStats DTO
$stats = $client->getStats();
echo "Memory: {$stats->getMemoryUsedFormatted()} / {$stats->getMemoryTotalFormatted()}\n";
echo "Uptime: {$stats->getUptimeFormatted()}\n";
echo "Active sessions: {$stats->activeSessions}\n";

// Ping the server
$alive = $client->ping();
```

### Video Metadata

```php
use App\Modules\Transcoder\Dto\VideoMetadata;

// Get video metadata - returns VideoMetadata DTO
$metadata = $client->getVideoMetadata('video123');

echo "Title: {$metadata->title}\n";
echo "Duration: {$metadata->getDurationFormatted()} ({$metadata->duration}s)\n";
echo "Resolution: {$metadata->getResolution()}\n"; // "1920x1080"
echo "FPS: {$metadata->fps}\n";
echo "Codec: {$metadata->codec}\n";

// Check if video exists
$exists = $client->videoExists('video123');
```

### Transcode Control

```php
use App\Modules\Transcoder\Dto\TranscodeJob;
use App\Modules\Transcoder\Dto\TranscodeStatus;

// Start a transcode job - returns TranscodeJob DTO
$job = $client->startTranscode('video123', [
    'qualities' => ['1080p', '720p'],
    'format' => 'hls',
]);

echo "Job: {$job->jobId}\n";
echo "Running: " . ($job->isRunning() ? 'Yes' : 'No') . "\n";
echo "Completed: " . ($job->isCompleted() ? 'Yes' : 'No') . "\n";

// Get job status - returns TranscodeStatus DTO
$status = $client->getTranscodeStatus($job->jobId);

echo "State: {$status->state}\n";
echo "Progress: {$status->getProgressPercent()}%\n";

if ($status->estimatedTimeRemainingSeconds !== null) {
    echo "ETA: {$status->getEtaFormatted()}\n";
}

if ($status->isComplete()) {
    echo "Transcoding complete!\n";
}

// Stop a transcode - returns TranscodeJob DTO
$result = $client->stopTranscode($job->jobId);

// Skip to specific time - returns TranscodeStatus DTO
$status = $client->skipTo($job->jobId, 30); // 30 seconds

// List all active transcodes - returns array of TranscodeJob
$jobs = $client->listTranscodes();
foreach ($jobs as $job) {
    echo "{$job->jobId}: {$job->status}\n";
}
```

### HLS Control

```php
// Get HLS master playlist (api returns this to the client)
$masterPlaylist = $client->getHlsMasterPlaylist('video123');

// Get HLS media playlist
$playlist = $client->getHlsMediaPlaylist('video123', '1080p');

// Get segment URL (api fronts storage)
$segmentUrl = $client->getHlsSegmentUrl('video123', '1080p', 0);
// Returns: "http://localhost:3000/api/hls/segment/video123/1080p/0.ts"

// Get complete stream URL for player
$streamUrl = $client->getStreamUrl('video123', 'hls');
```

### DASH Control

```php
// Get DASH manifest
$manifest = $client->getDashManifest('video123');

// Get DASH segment URL
$segmentUrl = $client->getDashSegmentUrl('video123', '1080p', 0);
```

### Session Management

```php
use App\Modules\Transcoder\Dto\SessionInfo;

// Get all active sessions - returns array of SessionInfo
$sessions = $client->getActiveSessions();

foreach ($sessions as $session) {
    echo "Session: {$session->id}\n";
    echo "  Variant: {$session->variantId} ({$session->height}p)\n";
    echo "  Format: {$session->format}\n";
    echo "  Max segment: {$session->maxSegment}\n";
    echo "  Age: {$session->getAgeSeconds()}s\n";
    echo "  Idle: {$session->getIdleSeconds()}s\n";
    echo "  Stale: " . ($session->isStale() ? 'Yes' : 'No') . "\n";
    echo "  Can restart: " . ($session->canRestart() ? 'Yes' : 'No') . "\n";
}

// Get sessions for a specific video
$videoSessions = $client->getActiveSessionsForVideo('video123');

// Get specific session info
$session = $client->getSessionInfo($session_id);
if ($session !== null) {
    echo "Session has {$session->userCount} active users\n";
}

// Terminate a session
$result = $client->terminateSession($session_id);
```

## Complete Example

```php
<?php

use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Dto\VideoMetadata;
use App\Modules\Transcoder\Dto\TranscodeJob;
use App\Modules\Transcoder\Exception\SocketException;

$client = new ControlClient('/tmp/transcoder.sock');

try {
    // 1. Start transcode if needed
    if (!$client->videoExists('video123')) {
        $job = $client->startTranscode('video123', [
            'qualities' => ['1080p', '720p', '480p'],
            'format' => 'hls',
        ]);
        echo "Started job: {$job->jobId}\n";
    }

    // 2. Get video metadata - type-safe DTO
    $metadata = $client->getVideoMetadata('video123');
    echo "Duration: {$metadata->getDurationFormatted()}\n";

    // 3. Get stream URL for frontend
    $streamUrl = $client->getStreamUrl('video123', 'hls');

    // 4. Return to frontend (JSON response)
    header('Content-Type: application/json');
    echo json_encode([
        'streamUrl' => $streamUrl,
        'duration' => $metadata->duration,
        'title' => $metadata->title,
        'resolution' => $metadata->getResolution(),
    ]);

    // Frontend JavaScript then does:
    // player.src = streamUrl;
    // (player fetches segments directly from Node.js)

} catch (SocketException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```

## Error Handling

```php
use App\Modules\Transcoder\Exception\{
    SocketException,
    ConnectionException,
    ResponseException,
};

try {
    $client = new ControlClient('/tmp/transcoder.sock');
    $playlist = $client->getHlsMasterPlaylist('video123');

} catch (ResponseException $e) {
    echo "HTTP Error {$e->getHttpStatusCode()}: {$e->getMessage()}";

} catch (ConnectionException $e) {
    echo "Failed to connect to {$e->getSocketPath()}";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}";
    echo "Context: " . json_encode($e->getContext());
}
```

## Connection Pooling

For high-concurrency scenarios (many simultaneous PHP processes), enable connection pooling to reduce connection
overhead:

```php
use App\Modules\Transcoder\ControlClient;

// Enable connection pooling
$client = (new ControlClient('/tmp/transcoder.sock'))
    ->withConnectionPool(
        maxPoolSize: 20,     // Maximum connections in pool
        idleTimeout: 60.0,   // Close idle connections after 60s
    );

// All requests now reuse connections from the pool
$health = $client->getHealth();
$metadata = $client->getVideoMetadata('video123');
$stats = $client->getStats();

// Get pool statistics
$poolStats = $client->getPoolStats();
echo "Active: {$poolStats['activeConnections']}, ";
echo "Idle: {$poolStats['idleConnections']}\n";

// Close pool when done
$client->closePool();
```

**When to use connection pooling:**

- Multiple concurrent requests from the same PHP process
- Long-running PHP processes (workers, daemons)
- High-throughput scenarios

**When NOT to use:**

- Simple scripts that make 1-2 requests
- Short-lived PHP-FPM requests (overhead of pool management)

## Concurrency & Simultaneous Writes

The implementation handles concurrent operations safely:

1. **PHP Side**: Each connection processes requests sequentially (request-response pattern)
2. **Connection Pool**: Reuses connections safely with automatic validation
3. **Node.js Server**: Uses semaphore-based concurrency control per connection
4. **Request IDs**: Automatic request ID generation for tracing and correlation

**Multiple PHP processes** can safely connect and send requests simultaneously - the OS and Node.js server handle
connection isolation.

## Request ID Tracing

Every request automatically gets a unique request ID for tracing:

```php
// Custom request ID (optional)
$client = new ControlClient('/tmp/transcoder.sock');
$metadata = $client->getVideoMetadata('video123');

// Server logs will show the request ID for debugging
// PHP-generated ID: php-1234567890abcdef-12345
```

Request IDs are:

- Automatically generated by PHP (format: `php-{timestamp}-{random}`)
- Echoed back in response headers (`x-request-id`)
- Logged by the Node.js server for debugging
- Useful for tracing requests through distributed systems

## Logging

The client supports structured logging for debugging and tracing:

```php
use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Logging\ConsoleLogger;

// Use console logger for development
$logger = new ConsoleLogger();

$client = new ControlClient('/tmp/transcoder.sock', logger: $logger);

// All operations are now logged with details
$health = $client->getHealth();
// Logs:
// [12:34:56.789] [INFO] Starting transcode {videoId="video123",options=[...]}
// [12:34:56.890] [INFO] Transcode started {jobId="xxx",videoId="video123",status="starting"}
```

**Built-in loggers:**

| Logger          | Description                              |
|-----------------|------------------------------------------|
| `NullLogger`    | Default - discards all logs              |
| `ConsoleLogger` | Colorized output to stderr (development) |
| `Psr3Logger`    | PSR-3 adapter for Monolog, etc.          |

**Using with Monolog:**

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use App\Modules\Transcoder\Logging\Psr3Logger;

$monolog = new Logger('transcoder');
$monolog->pushHandler(new StreamHandler('php://stderr'));

$logger = new Psr3Logger($monolog, prefix: 'Transcoder');
$client = new ControlClient('/tmp/transcoder.sock', logger: $logger);
```

**Logging with connection pooling:**

```php
$logger = new ConsoleLogger();

$client = (new ControlClient('/tmp/transcoder.sock', logger: $logger))
    ->withConnectionPool(maxPoolSize: 10);

// Logs include pool activity:
// [12:34:56.123] [INFO] ConnectionPool created {socketPath="/tmp/...",maxPoolSize=10}
// [12:34:56.234] [DEBUG] Reusing idle connection {connectionId=1,idleConnections=2}
// [12:34:56.345] [INFO] New connection created {connectionId=5,totalCreated=5}
```

## Examples

See `examples/control-plane.php` for basic examples, `examples/connection-pool.php` for pooling examples, and
`examples/logging.php` for logging examples.

## License

MIT
