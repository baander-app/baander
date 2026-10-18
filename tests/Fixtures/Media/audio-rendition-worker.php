<?php

declare(strict_types=1);

use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\NullLogger;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $schema, $delay] = $_SERVER['argv'];
if (preg_match('/^media_runtime_[a-f0-9]{16}$/D', $schema) !== 1) {
    throw new RuntimeException('Invalid disposable schema.');
}
$url = getenv('OUTBOX_TEST_DATABASE_URL') ?: throw new RuntimeException('Missing disposable database.');
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$connection->executeStatement('SET search_path TO ' . $schema . ', public');
$runtime = new AudioRenditionRuntime($connection);
// Pace real input and flush packets so the reader can observe an unfinished encode.
$client = new class(NativeExecutionClient::fromEnvironment('media-audio-0'), 3 / (float) $delay) implements NativeExecutionClientInterface {
    public function __construct(private NativeExecutionClientInterface $inner, private float $readRate) {}
    public function handshake(): array { return $this->inner->handshake(); }
    public function submit(array $argv, float $executionTimeoutSeconds = 600, ?int $attempt = null): NativeExecutionHandle
    {
        $input = array_search('-i', $argv, true);
        if ($input === false) {
            throw new RuntimeException('Audio fixture requires an input argument.');
        }
        array_splice($argv, $input, 0, ['-readrate', (string) $this->readRate]);
        array_splice($argv, -1, 0, ['-flush_packets', '1']);
        return $this->inner->submit($argv, $executionTimeoutSeconds, $attempt);
    }
    public function inspect(NativeExecutionHandle $handle): array { return $this->inner->inspect($handle); }
    public function signal(NativeExecutionHandle $handle, string $signal): array { return $this->inner->signal($handle, $signal); }
    public function release(NativeExecutionHandle $handle): bool { return $this->inner->release($handle); }
};
ProcessExecutor::configureNativeExecution($client);
try {
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        foreach ($runtime->pending() as $pending) {
            $claim = $runtime->claim($pending->key, new MediaWorkerIdentity('baander.app:test', str_repeat('b', 32), 'media', 1));
            if ($claim !== null) {
                (new AudioRenditionExecutor($runtime, new NullLogger(), '/usr/bin/ffmpeg'))->run($claim, static fn (): bool => false);
                return;
            }
        }
        usleep(10_000);
    }
    throw new RuntimeException('No durable audio admission became visible.');
} finally {
    ProcessExecutor::configureNativeExecution(null);
    $connection->close();
}
