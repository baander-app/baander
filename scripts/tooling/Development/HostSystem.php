<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

class HostSystem
{
    public function executable(string $name): ?string
    {
        return Environment::executable($name);
    }

    public function selinuxEnabled(): bool
    {
        return is_file('/sys/fs/selinux/enforce');
    }

    public function resolverOwned(): bool
    {
        return str_contains(realpath('/etc/resolv.conf') ?: '', '/NetworkManager/')
            || str_contains((string) @file_get_contents('/etc/resolv.conf'), 'NetworkManager');
    }

    public function assertPortAvailable(): void
    {
        $code = $this->bindPort();
        if ($code === 0) {
            return;
        }
        if ($code === 13 || $code === 1) {
            $connectCode = $this->connectPort();
            if ($connectCode === 111) {
                return;
            }
            if ($connectCode !== 0) {
                throw new WorkflowError('Cannot determine whether loopback port 443 is available to Docker.');
            }
        }
        throw new WorkflowError('Loopback port 443 is occupied; stop its owner before first gateway startup.');
    }

    protected function bindPort(): int
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:443', $code, $message);
        if ($listener === false) {
            return $code;
        }
        fclose($listener);
        return 0;
    }

    protected function connectPort(): int
    {
        $connection = @stream_socket_client('tcp://127.0.0.1:443', $code, $message, 1);
        if ($connection === false) {
            return $code;
        }
        fclose($connection);
        return 0;
    }
}
