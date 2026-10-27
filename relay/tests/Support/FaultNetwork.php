<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class FaultNetwork
{
    /**
     * @var list<int>
     */

    public array $ports;
    private Process $process;
    private string $control;
    private int $generation = 0;
    /**
     * @param list<int> $realPorts */
    public function __construct(public string $directory, public int $count, public array $realPorts)
    {
        $this->control = $directory . '/fault-control.json';
        file_put_contents(
            $this->control,
            json_encode(['generation' => 0, 'group' => null, 'blackhole' => false], JSON_THROW_ON_ERROR),
        );
        $this->process = new Process(
            [
                PHP_BINARY,
                __DIR__ . '/fault_network.php',
                $directory,
                json_encode($realPorts, JSON_THROW_ON_ERROR),
            ],
            $directory . '/fault-network.log',
        );
        try {
            $until = Runtime::now() + 5;
            do {
                Runtime::requireThat($this->process->poll() === null, 'Fault network exited during startup.');
                $ready = $directory . '/fault-ready.json';
                if (is_file($ready)) {
                    $this->ports = json_decode((string) file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR);
                    return;
                }
                usleep(5000);
            } while (Runtime::now() < $until);
            throw new \RuntimeException('Fault network did not start within five seconds.');
        } catch (\Throwable $failure) {
            $this->process->close(false);
            throw $failure;
        }
    }
    /**
     * @param list<int> $group */
    public function partition(array $group, bool $blackhole = false): void
    {
        $this->change($group, $blackhole);
    }
    public function heal(): void
    {
        $this->change(null, false);
    }
    /**
     * @param list<int>|null $group */
    private function change(?array $group, bool $blackhole): void
    {
        ++$this->generation;
        $temporary = $this->control . '.tmp';
        file_put_contents(
            $temporary,
            json_encode(
                ['generation' => $this->generation, 'group' => $group, 'blackhole' => $blackhole],
                JSON_THROW_ON_ERROR,
            ),
        );
        rename($temporary, $this->control);
        $until = Runtime::now() + 3;
        do {
            Runtime::requireThat($this->process->poll() === null, 'Fault network exited during partition.');
            if (is_file($this->control . '.ack') && trim((string) file_get_contents($this->control . '.ack')) === (string) $this->generation) {
                return;
            }
            usleep(5000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Fault network control deadline exceeded.');
    }
    public function close(): void
    {
        $this->process->close(false);
    }
}
