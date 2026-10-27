<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use RuntimeException;
use Throwable;

final class ContainerQualification
{
    /**
     * @param list<string> $createArguments
     * @param callable(Drill,string,string):void $operation
     * @param array<string,string> $evidence container path to artifact name
     */
    public static function run(
        string $root,
        string $prefix,
        array $createArguments,
        callable $operation,
        array $evidence,
        ?float $createTimeout = 20,
    ): int {
        $name = $prefix . '-' . bin2hex(random_bytes(8));
        $artifacts = sys_get_temp_dir() . '/' . $name;
        if (!mkdir($artifacts, 0700)) {
            throw new RuntimeException('Cannot create HTTP fixture artifacts');
        }
        printf("HTTP fixture artifacts: %s\n", $artifacts);
        $drill = new Drill($root);
        $registered = false;
        $error = null;
        $cleanupErrors = [];
        $remaining = [];
        $status = 1;
        $started = hrtime(true) / 1e9;
        try {
            $drill->connect();
            $registered = true;
            $drill->docker(
                [
                    'create',
                    '--name', $name,
                    '--label', 'com.baander.http.run=' . $name,
                    ...$createArguments,
                ],
                timeout: $createTimeout,
            );
            $drill->docker(['start', $name]);
            $operation($drill, $name, $artifacts);
            $status = 0;
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
            $status = $failure instanceof DrillFailure ? $failure->status : 1;
            fwrite(STDERR, $error . "\n");
        } finally {
            $drill->cleanup = true;
            if ($registered) {
                foreach ($evidence as $path => $filename) {
                    try {
                        $drill->docker(['cp', $name . ':' . $path, $artifacts . '/' . $filename], timeout: 15);
                    } catch (Throwable $failure) {
                        file_put_contents($artifacts . '/evidence-errors.log', $failure->getMessage() . "\n", FILE_APPEND);
                    }
                }
                try {
                    $drill->docker(['logs', $name], timeout: 10, check: false, log: $artifacts . '/container.log');
                } catch (Throwable $failure) {
                    file_put_contents($artifacts . '/evidence-errors.log', $failure->getMessage() . "\n", FILE_APPEND);
                }
                try {
                    $drill->docker(['rm', '-f', '--volumes', $name], timeout: 15);
                } catch (Throwable $failure) {
                    $cleanupErrors[] = $failure->getMessage();
                }
                try {
                    $result = $drill->docker(['ps', '-a', '-q', '--filter', 'label=com.baander.http.run=' . $name], timeout: 10);
                    file_put_contents($artifacts . '/after-cleanup-containers.log', $result->stdout . $result->stderr);
                    $remaining = preg_split('/\s+/', trim($result->stdout))
                        |> (static fn(array $values): array => array_values(array_filter($values)));
                    if ($remaining !== []) {
                        $cleanupErrors[] = 'HTTP fixture containers remain after cleanup: ' . implode(', ', $remaining);
                    }
                } catch (Throwable $failure) {
                    $cleanupErrors[] = $failure->getMessage();
                }
            }
            if ($cleanupErrors !== []) {
                $status = 1;
                fwrite(STDERR, implode("\n", $cleanupErrors) . "\n");
            }
            $drill->close();
            file_put_contents(
                $artifacts . '/results.json',
                json_encode([
                    'container' => $name,
                    'status' => $status,
                    'error' => $error,
                    'cleanup_errors' => $cleanupErrors,
                    'remaining_containers' => $remaining,
                    'elapsed' => hrtime(true) / 1e9 - $started,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
            );
        }
        return $status;
    }
}
