<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use Symfony\Component\Process\Process;

final class NativeExecutionFixture
{
    private readonly string $directory;
    private readonly Process $daemon;
    public readonly NativeExecutionClient $client;

    public function __construct(string $namespace, string $bootId, int $epoch = 1)
    {
        $this->directory = sys_get_temp_dir() . '/baander-native-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Cannot create native execution fixture directory.');
        }
        $socket = $this->directory . '/native.sock';
        $this->daemon = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker-native.php'], env: [
            'BAANDER_NATIVE_SOCKET' => $socket,
            'BAANDER_WORKER_NAMESPACE' => $namespace,
            'BAANDER_WORKER_BOOT_ID' => $bootId,
            'BAANDER_WORKER_LEASE_EPOCH' => (string) $epoch,
        ]);
        $this->client = new NativeExecutionClient($socket, $namespace, $bootId, $epoch, 'media-audio-0', 1);
        try {
            $this->daemon->start();
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                if (file_exists($socket) && ($this->client->handshake()['ready'] ?? null) === true) {
                    return;
                }
                usleep(10_000);
            } while ($this->daemon->isRunning() && hrtime(true) < $deadline);
            throw new \RuntimeException('Native execution fixture did not become ready: ' . $this->daemon->getErrorOutput());
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function close(): void
    {
        try {
            $this->daemon->stop(2);
        } finally {
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    public function socketPath(): string
    {
        return $this->directory . '/native.sock';
    }
}
