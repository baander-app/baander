<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class BackupFixture
{
    public readonly string $root;
    /** @var array<string, mixed> */
    public array $config;

    public function __construct(public readonly string $python, public readonly string $coordinator)
    {
        $this->root = Runtime::temporaryDirectory('registry-backup-contract-');
        foreach (['bin', 'spool', 'objects'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700);
        }
        foreach (['curl', 'age', 'rclone'] as $name) {
            $target = $this->root . '/bin/' . $name;
            file_put_contents($target, '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/backup_fake_tool.php'));
            chmod($target, 0700);
        }
        file_put_contents($this->root . '/rclone.conf', "[backup]\ntype=s3\nendpoint=https://storage.baander.app\n");
        file_put_contents($this->root . '/curl.conf', 'user = "private-user:private-password"' . "\n");
        file_put_contents($this->root . '/recipients', "controlled-public-recipient\n");
        $this->config = [
            'rqlite_url' => 'https://registry.baander.app:4001',
            'curl_config' => $this->root . '/curl.conf',
            'recipients_file' => $this->root . '/recipients',
            'rclone_config' => $this->root . '/rclone.conf',
            'destination' => 'backup:registry/snapshots',
            'spool_dir' => $this->root . '/spool',
            'status_file' => $this->root . '/status.json',
            'attempt_timeout_seconds' => 2,
            'max_ciphertext_bytes' => 4096,
        ];
    }

    /** @return array<string, string> */
    public function environment(string $mode = ''): array
    {
        return [
            ...getenv(),
            'PATH' => $this->root . '/bin:' . getenv('PATH'),
            'BACKUP_FIXTURE_ROOT' => $this->root,
            'BACKUP_FIXTURE_MODE' => $mode,
        ];
    }

    /** @return list<string> */
    public function arguments(string $command = 'once'): array
    {
        file_put_contents($this->root . '/config.json', json_encode($this->config, JSON_THROW_ON_ERROR));
        return [$this->python, $this->coordinator, $command, '--config', $this->root . '/config.json'];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    public function invoke(string $mode = '', string $command = 'once'): array
    {
        return Runtime::run($this->arguments($command), timeout: 10, env: $this->environment($mode));
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return json_decode(file_get_contents($this->root . '/status.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array{string,int,list<string>,float}> */
    public function calls(): array
    {
        $path = $this->root . '/calls';
        if (!is_file($path)) {
            return [];
        }
        return array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function clearCalls(): void
    {
        if (is_file($this->root . '/calls')) {
            unlink($this->root . '/calls');
        }
    }

    public function assertClean(): void
    {
        Runtime::requireThat(array_values(array_diff(scandir($this->root . '/spool'), ['.', '..'])) === [], 'Backup retained spool artifacts.');
        foreach ($this->calls() as [, $pid]) {
            Runtime::requireThat(!self::isOwnedChild($pid, $this->root), 'Backup retained a child process: ' . $pid);
        }
    }

    public function stopChildren(): void
    {
        foreach ($this->calls() as [, $pid]) {
            if (self::isOwnedChild($pid, $this->root)) {
                @\Swoole\Process::kill($pid, 9);
            }
        }
    }

    private static function isOwnedChild(int $pid, string $root): bool
    {
        if (!@\Swoole\Process::kill($pid, 0)) {
            return false;
        }
        // Long-running cadence checks must not signal an unrelated process
        // if Linux has recycled a completed fixture command's PID.
        $command = @file_get_contents('/proc/' . $pid . '/cmdline');
        // A zombie has an empty command line and still proves a child was
        // not reaped. Sending it a signal has no effect on another process.
        return $command === '' || ($command !== false && str_contains($command, $root . '/bin/'));
    }

    public function close(): void
    {
        $this->stopChildren();
        Runtime::removeTree($this->root);
    }
}
