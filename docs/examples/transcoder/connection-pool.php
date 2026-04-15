<?php

/**
 * Connection Pool Example
 *
 * Demonstrates how to use connection pooling for high-concurrency scenarios.
 * Connection pooling reuses socket connections, reducing overhead for
 * multiple simultaneous requests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Dto\HealthStatus;
use App\Modules\Transcoder\Exception\SocketException;

$socketPath = '/tmp/transcoder.sock';

// ============================================================================
// Example 1: Basic Connection Pooling
// ============================================================================
echo "=== Example 1: Basic Connection Pooling ===\n";

try {
    // Create client with connection pooling enabled
    $client = new ControlClient($socketPath);
    $client->withConnectionPool(
        maxPoolSize: 10,      // Maximum 10 connections in the pool
        idleTimeout: 60.0,    // Close idle connections after 60 seconds
    );

    // All requests now reuse connections from the pool
    $health = $client->getHealth();
    echo "Health: {$health->status}\n";

    // Get pool statistics
    $stats = $client->getPoolStats();
    echo "Pool stats:\n";
    echo "  Active connections: {$stats['activeConnections']}\n";
    echo "  Idle connections: {$stats['idleConnections']}\n";
    echo "  Total created: {$stats['totalCreated']}\n\n";

    // Close the pool when done
    $client->closePool();

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 2: High Concurrency Scenario
// ============================================================================
echo "=== Example 2: High Concurrency Requests ===\n";

try {
    $client = new ControlClient($socketPath);
    $client->withConnectionPool(
        maxPoolSize: 20,
        idleTimeout: 30.0,
    );

    // Simulate 10 concurrent video metadata requests
    $videoIds = ['video1', 'video2', 'video3', 'video4', 'video5'];
    $results = [];

    foreach ($videoIds as $videoId) {
        try {
            $metadata = $client->getVideoMetadata($videoId);
            $results[$videoId] = [
                'title'    => $metadata->title,
                'duration' => $metadata->getDurationFormatted(),
            ];
        } catch (SocketException $e) {
            $results[$videoId] = ['error' => $e->getMessage()];
        }
    }

    echo "Results:\n";
    foreach ($results as $videoId => $result) {
        if (isset($result['error'])) {
            echo "  {$videoId}: Error - {$result['error']}\n";
        } else {
            echo "  {$videoId}: {$result['title']} ({$result['duration']})\n";
        }
    }

    $stats = $client->getPoolStats();
    echo "\nPool reused {$stats['totalCreated']} connections for " . count($videoIds) . " requests\n\n";

    $client->closePool();

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 3: Fluent Interface with Pooling
// ============================================================================
echo "=== Example 3: Fluent Interface ===\n";

try {
    $client = new ControlClient($socketPath)
        ->withConnectionPool(maxPoolSize: 5);

    $health = $client->getHealth();
    echo "Health check: " . ($health->isHealthy() ? 'OK' : 'Failed') . "\n";

    // Pool automatically manages connections
    $stats = $client->getPoolStats();
    echo "Pool is managing up to {$stats['maxPoolSize']} connections\n\n";

    $client->closePool();

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 4: Comparing With and Without Pooling
// ============================================================================
echo "=== Example 4: Performance Comparison ===\n";

function benchmarkWithoutPool(int $iterations = 10): float
{
    $client = new ControlClient('/tmp/transcoder.sock');
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        try {
            $client->getHealth();
        } catch (SocketException $e) {
            // Ignore errors for benchmark
        }
    }

    return microtime(true) - $start;
}

function benchmarkWithPool(int $iterations = 10): float
{
    $client = new ControlClient('/tmp/transcoder.sock');
    $client->withConnectionPool(maxPoolSize: 5);
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        try {
            $client->getHealth();
        } catch (SocketException $e) {
            // Ignore errors for benchmark
        }
    }

    $client->closePool();
    return microtime(true) - $start;
}

$iterations = 5;
$withoutPool = benchmarkWithoutPool($iterations);
$withPool = benchmarkWithPool($iterations);

echo "Iterations: {$iterations}\n";
echo sprintf("Without pool: %.4f seconds\n", $withoutPool);
echo sprintf("With pool:    %.4f seconds\n", $withPool);

if ($withPool < $withoutPool) {
    $improvement = (($withoutPool - $withPool) / $withoutPool) * 100;
    echo sprintf("Improvement: %.1f%% faster\n", $improvement);
}

echo "\nExamples completed.\n";
