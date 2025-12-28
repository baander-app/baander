<?php

/**
 * Logging Example
 *
 * Demonstrates how to use logging with the transcoder client
 * for debugging and tracing requests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Transcoder\ControlClient;
use App\Modules\Transcoder\Dto\HealthStatus;
use App\Modules\Transcoder\Exception\SocketException;
use App\Modules\Transcoder\Logging\ConsoleLogger;
use App\Modules\Transcoder\Logging\LoggerInterface;

$socketPath = '/tmp/transcoder.sock';

// ============================================================================
// Example 1: Console Logging (Development)
// ============================================================================
echo "=== Example 1: Console Logging ===\n\n";

try {
    // Use console logger for development/debugging
    $logger = new ConsoleLogger();

    $client = new ControlClient($socketPath, logger: $logger);

    // All operations will be logged to console
    /** @var HealthStatus $health */
    $health = $client->getHealth();

    echo "Health: {$health->status}\n";
    echo "Version: {$health->version}\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 2: Connection Pooling with Logging
// ============================================================================
echo "=== Example 2: Connection Pooling with Logging ===\n\n";

try {
    $logger = new ConsoleLogger();

    $client = new ControlClient($socketPath, logger: $logger)
        ->withConnectionPool(maxPoolSize: 5);

    // Make multiple requests - watch the logs!
    $client->getHealth();
    $stats = $client->getStats();

    echo "Active sessions: {$stats->activeSessions}\n";
    echo "Memory: {$stats->getMemoryUsedFormatted()}\n\n";

    // View pool statistics
    $poolStats = $client->getPoolStats();
    echo "Pool stats:\n";
    echo "  Total created: {$poolStats['totalCreated']}\n";
    echo "  Active: {$poolStats['activeConnections']}\n";
    echo "  Idle: {$poolStats['idleConnections']}\n\n";

    $client->closePool();

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 3: Custom Logger with Prefix
// ============================================================================
echo "=== Example 3: Custom Logger Implementation ===\n\n";

/**
 * Simple file logger for demonstration
 */
class SimpleFileLogger implements LoggerInterface
{
    private $handle;

    public function __construct(private readonly string $filepath)
    {
        $this->handle = fopen($filepath, 'a');
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s.v');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $message, $contextStr);
        fwrite($this->handle, $line);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function __destruct()
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}

try {
    // Log to file for production
    $fileLogger = new SimpleFileLogger(__DIR__ . '/transcoder.log');

    $client = new ControlClient($socketPath, logger: $fileLogger);

    $health = $client->getHealth();

    echo "Health check logged to: transcoder.log\n";
    echo "Status: {$health->status}\n\n";

} catch (SocketException $e) {
    echo "Error: {$e->getMessage()}\n";
}

// ============================================================================
// Example 4: Using PSR-3 Logger (Monolog)
// ============================================================================
echo "=== Example 4: PSR-3 Logger (Monolog) ===\n\n";

echo "If you have Monolog installed:\n";
echo <<<PHP
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use App\Modules\Transcoder\Logging\Psr3Logger;

// Create Monolog logger
$monolog = new Logger('transcoder');
$monolog->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

// Wrap with PSR-3 adapter
$logger = new Psr3Logger($monolog, prefix: 'TranscoderClient');

// Use with ControlClient
$client = new ControlClient('/tmp/transcoder.sock', logger: $logger);
PHP;

echo "\nExamples completed.\n";
