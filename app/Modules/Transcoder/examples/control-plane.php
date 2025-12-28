<?php

/**
 * Control Plane Examples
 *
 * Demonstrates PHP as the control plane for the transcoder service.
 * PHP handles playlists and control operations, while media delivery
 * happens directly between browsers and the Node.js server.
 *
 * This version uses type-safe DTOs for better developer experience.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Dto\HealthStatus;
use App\Modules\Transcoder\Dto\ServerStats;
use App\Modules\Transcoder\Dto\SessionInfo;
use App\Modules\Transcoder\Dto\TranscodeJob;
use App\Modules\Transcoder\Dto\TranscodeStatus;
use App\Modules\Transcoder\Dto\VideoMetadata;
use App\Modules\Transcoder\Exception\SocketException;

$socketPath = '/tmp/transcoder.sock';

// ============================================================================
// Example 1: Health Check
// ============================================================================
echo "=== Example 1: Health Check ===\n";

try {
    $client = new ControlClient($socketPath);

    $health = $client->getHealth();
    echo "Server status: {$health->status}\n";
    echo "Version: {$health->version}\n";
    echo "Healthy: " . ($health->isHealthy() ? 'Yes' : 'No') . "\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 2: Server Statistics
// ============================================================================
echo "=== Example 2: Server Statistics ===\n";

try {
    $client = new ControlClient($socketPath);

    $stats = $client->getStats();
    echo "Active sessions: $stats->activeSessions\n";
    echo "Memory used: {$stats->getMemoryUsedFormatted()} / {$stats->getMemoryTotalFormatted()}\n";
    echo "Uptime: {$stats->getUptimeFormatted()}\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 3: Start Transcode
// ============================================================================
echo "=== Example 3: Start Transcode ===\n";

try {
    $client = new ControlClient($socketPath);

    // Start a transcode job
    $job = $client->startTranscode('my-video', [
        'qualities' => ['1080p', '720p', '480p'],
        'format'    => 'hls',
    ]);

    echo "Started job: {$job->jobId}\n";
    echo "Status: {$job->status}\n";
    echo "Is running: " . ($job->isRunning() ? 'Yes' : 'No') . "\n\n";

    // Get the stream URL to pass to the video player
    $streamUrl = $client->getStreamUrl('my-video', 'hls');
    echo "Stream URL: {$streamUrl}\n";
    echo "Pass this URL to your video player (HLS.js, Video.js, etc.)\n";
    echo "The player will fetch video segments directly from Node.js\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 4: Video Metadata
// ============================================================================
echo "=== Example 4: Video Metadata ===\n";

try {
    $client = new ControlClient($socketPath);
    $videoId = 'my-video';

    $metadata = $client->getVideoMetadata($videoId);
    echo "Title: $metadata->title\n";
    echo "Duration: {$metadata->getDurationFormatted()} ({$metadata->duration}s)\n";
    echo "Resolution: {$metadata->getResolution()}\n";
    echo "FPS: $metadata->fps\n";
    echo "Codec: $metadata->codec\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 5: Monitor Transcode Progress
// ============================================================================
echo "=== Example 5: Monitor Transcode Progress ===\n";

try {
    $client = new ControlClient($socketPath);
    $jobId = 'job-123'; // From previous startTranscode call

    // Poll job status
    for ($i = 0; $i < 5; $i++) {
        $status = $client->getTranscodeStatus($jobId);

        echo "Poll {$i}: {$status->state} - ";
        echo $status->getProgressPercent() !== null ? $status->getProgressPercent() . '%' : 'N/A';

        if ($status->estimatedTimeRemainingSeconds !== null) {
            echo " - ETA: {$status->getEtaFormatted()}";
        }
        echo "\n";

        if ($status->isComplete() || $status->isFailed()) {
            break;
        }

        sleep(1);
    }

    echo "\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 6: List Active Transcodes
// ============================================================================
echo "=== Example 6: List Active Transcodes ===\n";

try {
    $client = new ControlClient($socketPath);

    /** @var array<int, TranscodeJob> $transcodes */
    $transcodes = $client->listTranscodes();
    echo "Active transcodes: " . count($transcodes) . "\n";

    foreach ($transcodes as $job) {
        $running = $job->isRunning() ? 'Running' : 'Stopped';
        echo "  - {$job->jobId}: {$job->status} ({$running})\n";
    }

    echo "\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 7: Session Management
// ============================================================================
echo "=== Example 7: Session Management ===\n";

try {
    $client = new ControlClient($socketPath);

    /** @var array<int, SessionInfo> $sessions */
    $sessions = $client->getActiveSessions();
    echo "Active sessions: " . count($sessions) . "\n";

    foreach ($sessions as $session) {
        echo "  - Session: {$session->id}\n";
        echo "    Variant: {$session->variantId} ({$session->height}p)\n";
        echo "    Format: {$session->format}\n";
        echo "    Max segment: {$session->maxSegment}\n";
        echo "    Age: {$session->getAgeSeconds()}s\n";
        echo "    Idle: {$session->getIdleSeconds()}s\n";
        echo "    Stale: " . ($session->isStale() ? 'Yes' : 'No') . "\n";
    }

    echo "\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 8: Web Page Integration
// ============================================================================
echo "=== Example 8: Web Page Integration ===\n";

/**
 * Get video stream info for frontend
 *
 * @return array{videoId: string, streamUrl: string, duration: int, title: string}
 */
function getVideoStreamInfo(string $videoId): array
{
    $client = new ControlClient('/tmp/transcoder.sock');

    // Check if video exists
    if (!$client->videoExists($videoId)) {
        // Start transcode if doesn't exist
        $client->startTranscode($videoId);
    }

    // Get metadata
    $metadata = $client->getVideoMetadata($videoId);

    // Get stream URL
    $streamUrl = $client->getStreamUrl($videoId, 'hls');

    return [
        'videoId'    => $videoId,
        'streamUrl'  => $streamUrl,
        'duration'   => $metadata->duration,
        'title'      => $metadata->title,
        'resolution' => $metadata->getResolution(),
    ];
}

// Simulate a web request
$videoInfo = getVideoStreamInfo('my-video');

echo "PHP would return this JSON to frontend:\n";
echo json_encode($videoInfo, JSON_PRETTY_PRINT) . "\n\n";

echo "Examples completed.\n";
