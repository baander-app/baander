<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Observer
{
    public int $port;
    private Process $process;
    private string $observations;
    public function __construct(Cluster $cluster, int $node)
    {
        $this->port = Runtime::port();
        $this->observations = $cluster->directory . '/observations.jsonl';
        file_put_contents($this->observations, '');
        $this->process = new Process(
            [
                PHP_BINARY,
                __DIR__ . '/observer.php',
                $cluster->directory,
                (string) $node,
                (string) $cluster->httpPorts[$node],
                (string) $this->port,
            ],
            $cluster->directory . '/observer.log',
        );
        $until = Runtime::now() + 5;
        do {
            Runtime::requireThat($this->process->poll() === null, 'HTTP observer exited before readiness.');
            if (is_file($cluster->directory . '/observer.ready')) {
                return;
            }
            usleep(5000);
        } while (Runtime::now() < $until);
        $this->close();
        throw new \RuntimeException('HTTP observer readiness deadline exceeded.');
    }
    public function clear(): void
    {
        file_put_contents($this->observations, '', LOCK_EX);
    }
    /**
     * @return list<array{status:int,error:bool}> */
    public function snapshot(): array
    {
        return array_map(
            static fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->observations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }
    public function close(): void
    {
        $this->process->close(false);
    }
}
