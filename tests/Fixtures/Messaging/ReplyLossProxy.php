<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Process\Process;

/** Owns one fresh-exec, deadline-limited RESP2 reply-loss proxy. */
final class ReplyLossProxy
{
    private Process $process;
    private string $prefix;

    public function __construct(string $directory, string $host, int $port, string $command, string $ledgerPath)
    {
        $this->prefix = $directory . '/reply-loss';
        file_put_contents($this->prefix . '.config', json_encode([
            'host' => $host, 'port' => $port, 'command' => $command, 'ledger' => $ledgerPath,
        ], JSON_THROW_ON_ERROR));
        $this->process = new Process([PHP_BINARY, __DIR__ . '/ReplyLossProxyServer.php', $this->prefix]);
        $this->process->start();
        $deadline = microtime(true) + 3;
        while (!is_file($this->prefix . '.ready')) {
            if (!$this->process->isRunning() || microtime(true) >= $deadline) {
                $this->close();
                throw new \RuntimeException('Reply-loss proxy failed to start: ' . $this->process->getErrorOutput());
            }
            usleep(1000);
        }
    }

    public function port(): int
    {
        return (int) file_get_contents($this->prefix . '.ready');
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        // A transport exception alone is never evidence that Redis accepted work.
        if (!is_file($this->prefix . '.receipt')) {
            throw new \RuntimeException('No accepted Redis command receipt: ' . $this->process->getErrorOutput());
        }
        return json_decode((string) file_get_contents($this->prefix . '.receipt'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function close(): void
    {
        $this->process->stop(0.2);
        foreach (glob($this->prefix . '.*') ?: [] as $file) {
            unlink($file);
        }
    }
}
