<?php

declare(strict_types=1);

namespace Baander\Registry\Qualification;

final class Runtime
{
    public static function requireThat(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** @param list<string> $arguments */
    public static function checked(array $arguments): string
    {
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::requireThat(is_resource($process), 'Cannot start filesystem inspection.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = hrtime(true) + 30_000_000_000;
        try {
            do {
                $output .= stream_get_contents($pipes[1]);
                $errors .= stream_get_contents($pipes[2]);
                $state = proc_get_status($process);
                if (!$state['running']) {
                    break;
                }
                self::requireThat(hrtime(true) < $deadline, 'Filesystem inspection timed out.');
                usleep(1000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
            $errors .= stream_get_contents($pipes[2]);
            self::requireThat($state['exitcode'] === 0, 'Filesystem inspection failed: ' . $errors);
            return $output;
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            self::requireThat(unlink($path), 'Cannot remove ' . $path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            self::removeTree($entry->getPathname());
        }
        self::requireThat(rmdir($path), 'Cannot remove ' . $path);
    }
}
