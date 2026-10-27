<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

use RuntimeException;
use Swoole\Process as SwooleProcess;

final class Process
{
    /**
     * @var resource|null
     */

    private $process;
    /**
     * @var array<int,resource>
     */

    private array $pipes = [];
    private ?int $exitCode = null;
    private int $processId;
    public string $stdout = '';
    public string $stderr = '';
    private bool $inputClosed = false;
    /**
     * @param list<string> $argv
     * @param array<string,string>|null $env
     */
    public function __construct(array $argv, ?string $log = null, ?array $env = null, ?string $cwd = null)
    {
        Runtime::baseline();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($log !== null) {
            $descriptors[1] = ['file', $log, 'a'];
            $descriptors[2] = ['redirect', 1];
        }
        // Restore the signal mask inherited from Swoole's signalfd before exec.
        $command = ['env', '--default-signal=INT,TERM', '--', ...$argv];
        $this->process = proc_open($command, $descriptors, $this->pipes, $cwd, $env, ['bypass_shell' => true]);
        Runtime::requireThat(is_resource($this->process), 'Cannot start command: ' . json_encode($argv));
        $status = proc_get_status($this->process);
        $this->processId = $status['pid'];
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }
    public function pid(): int
    {
        return $this->processId;
    }
    public function input(string $input): void
    {
        if ($this->inputClosed) {
            return;
        }
        $offset = 0;
        $until = Runtime::now() + 10;
        while ($offset < strlen($input)) {
            $written = fwrite($this->pipes[0], substr($input, $offset));
            Runtime::requireThat(
                $written !== false && Runtime::now() < $until,
                'Cannot write child input within ten seconds.',
            );
            $offset += $written;
            $this->pump();
            if ($written === 0) {
                usleep(1000);
            }
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        $this->inputClosed = true;
    }
    private function pump(): void
    {
        foreach ([1, 2] as $number) {
            if (!isset($this->pipes[$number])) {
                continue;
            }
            $data = stream_get_contents($this->pipes[$number]);
            Runtime::requireThat($data !== false, 'Cannot read child output.');
            if ($number === 1) {
                $this->stdout .= $data;
            } else {
                $this->stderr .= $data;
            }
            Runtime::requireThat(
                strlen($this->stdout) + strlen($this->stderr) <= 32 * 1024 * 1024,
                'Child output exceeded 32 MiB.',
            );
        }
    }
    public function poll(): ?int
    {
        $this->pump();
        if ($this->exitCode !== null || !is_resource($this->process)) {
            return $this->exitCode;
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : 128 + $status['termsig'];
        }
        return $this->exitCode;
    }
    public function signal(int $signal): void
    {
        if ($this->poll() === null) {
            Runtime::requireThat(SwooleProcess::kill($this->processId, $signal), 'Cannot signal owned child.');
        }
    }
    public function wait(float $timeout): int
    {
        $until = Runtime::now() + $timeout;
        while (($code = $this->poll()) === null) {
            if (Runtime::now() >= $until) {
                throw new RuntimeException('Child wait deadline exceeded.');
            }
            usleep(5000);
        }
        $this->pump();
        return $code;
    }
    public function close(bool $requireZero = true): void
    {
        try {
            if ($this->poll() === null) {
                $this->signal(15);
                try {
                    $this->wait(7);
                } catch (RuntimeException) {
                    $this->signal(9);
                    $this->wait(3);
                }
            }
            if ($requireZero) {
                Runtime::requireThat($this->exitCode === 0, 'Child exited abnormally (' . $this->exitCode . ').');
            }
        } finally {
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            $this->pipes = [];
            if (is_resource($this->process)) {
                proc_close($this->process);
            }
            $this->process = null;
        }
    }
    public function __destruct()
    {
        if (is_resource($this->process)) {
            $this->close(false);
        }
    }
}
