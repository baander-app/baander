<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Resources\ResourceKind;
use Throwable;

final class ContainerQualification
{
    /**
     * @param list<string> $createArguments
     * @param callable(Runtime,string,string):void $operation
     * @param array<string,string> $evidence container path to artifact name
     */
    public static function run(Runtime $runtime, string $prefix, array $createArguments, callable $operation, array $evidence): int
    {
        $name = $prefix . '-' . bin2hex(random_bytes(8));
        $artifacts = Runtime::artifacts($runtime->root, $name, $runtime->environment);
        printf("HTTP fixture artifacts: %s\n", $artifacts);
        $ledger = null;
        $error = null;
        $cleanupErrors = [];
        $remaining = [];
        $status = 0;
        $started = Runtime::now();
        try {
            $docker = $runtime->docker();
            $docker->assertLocalPublishedPorts();
            $ledger = $runtime->resources($artifacts);
            $container = $ledger->create(ResourceKind::Container, $name, $createArguments);
            $docker->run(['start', $container]);
            $operation($runtime, $container, $artifacts);
        } catch (Throwable $failure) {
            $error = Runtime::failure($failure);
            $status = Runtime::status($failure);
            fwrite(STDERR, $error . "\n");
        } finally {
            $runtime->cleaning = true;
            if ($ledger !== null) {
                $docker = $runtime->docker();
                foreach ($evidence as $path => $filename) {
                    try {
                        $docker->run(['cp', ($container ?? $name) . ':' . $path, $artifacts . '/' . $filename], timeout: 15);
                    } catch (Throwable $failure) {
                        file_put_contents($artifacts . '/evidence-errors.log', $failure->getMessage() . "\n", FILE_APPEND);
                    }
                }
                try {
                    $docker->run(['logs', $container ?? $name], timeout: 10, check: false, logPath: $artifacts . '/container.log');
                } catch (Throwable $failure) {
                    file_put_contents($artifacts . '/evidence-errors.log', $failure->getMessage() . "\n", FILE_APPEND);
                }
                try {
                    $report = $ledger->cleanup((float) $runtime->cleanupExecutor()->context->remaining());
                    $cleanupErrors = $report->errors;
                } catch (Throwable $failure) {
                    $cleanupErrors[] = $failure->getMessage();
                }
                if ($cleanupErrors !== []) {
                    $status = $status === 0 ? 1 : $status;
                    fwrite(STDERR, implode("\n", $cleanupErrors) . '; retry receipt: ' . $ledger->receipt . "\n");
                    if (is_file($ledger->receipt)) {
                        $remaining = array_column(AtomicFile::readJson($ledger->receipt)['resources'], 'name');
                    }
                }
            }
            try {
                $runtime->close();
            } catch (Throwable $failure) {
                $cleanupErrors[] = Runtime::failure($failure);
                $status = $status === 0 ? 1 : $status;
            }
            Runtime::writeJson($artifacts . '/results.json', [
                'container' => $name,
                'status' => $status,
                'error' => $error,
                'cleanup_errors' => $cleanupErrors,
                'remaining_containers' => $remaining,
                'elapsed' => Runtime::now() - $started,
            ]);
        }
        return $status;
    }
}
