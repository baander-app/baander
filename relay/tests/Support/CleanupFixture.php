<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class CleanupFixture
{
    public static function run(string $mode, string $path): void
    {
        if ($mode === 'api') {
            $config = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $port = $config['api']['port'];
            $listener = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
            Runtime::requireThat($listener !== false, $error);
            file_put_contents(dirname($path) . '/cleanup-child.pid', (string) getmypid());
            try {
                for (;;) {
                    $client = @stream_socket_accept($listener, 1);
                    if ($client !== false) {
                        fclose($client);
                    }
                }
            } finally {
                fclose($listener);
            }
        }
        if ($mode === 'ignore') {
            \Swoole\Process::signal(15, static function (): void {});
        }
        file_put_contents($path, (string) getmypid());
        for (;;) {
            usleep(10000);
        }
    }
}
