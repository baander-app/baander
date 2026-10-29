#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (in_array($arguments[0] ?? '', ['--help', '-h'], true)) {
    echo "Usage: php scripts/verify-registry-image.php --image IMAGE --kind api|rqlite|backup --output DIRECTORY\n";
    echo "Verify an existing local image and export its packaged inventory evidence.\n";
    echo "Docker selection and finite budgets use the repository tooling configuration.\n";
    exit(0);
}

$status = 0;
$runner = null;
$receipt = null;
$configuration = null;
$environment = getenv();
$root = dirname(__DIR__);
try {
    Requirements::check();
    $configuration = ToolingConfiguration::forRepository($root, $environment);
    $runner = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('registryQualification'), $environment, $root));
    $evidence = $root . '/var/test-runs/registry-image/' . bin2hex(random_bytes(16));
    PrivateDirectory::prepare($evidence);
    $target = new TargetResolver($runner, $environment)->resolve($configuration->docker, $evidence . '/transport');
    $targetFile = $evidence . '/target.json';
    AtomicFile::writeJson($targetFile, $target->toArray());
    $receipt = $evidence . '/relay-resources.json';
    $environment = Client::transportEnvironment($environment);
    $environment['BAANDER_TOOLING_TARGET_FILE'] = $targetFile;
    $environment['BAANDER_REGISTRY_OWNERSHIP_RECEIPT'] = $receipt;
    $environment['BAANDER_CLEANUP_TIMEOUT'] = (string) $configuration->budgets->seconds('cleanup');
    $runner->run(new ProcessSpec(
        [PHP_BINARY, $root . '/relay/tools/verify_packaged_image.php', ...$arguments],
        environment: $environment,
        passthrough: true,
        processGroup: true,
        replaceEnvironment: true,
    ));
} catch (Throwable $failure) {
    $status = $failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1;
    fwrite(STDERR, $failure->getMessage() . "\n");
} finally {
    try {
        $runner?->close();
    } catch (Throwable $failure) {
        $status = $status === 0 ? 1 : $status;
        fwrite(STDERR, 'Registry image executor close failed: ' . $failure->getMessage() . "\n");
    }
    if ($receipt !== null && (file_exists($receipt) || is_link($receipt))) {
        $cleanup = null;
        try {
            $cleanup = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('cleanup'), $environment, $root, ignoreCancellation: true));
            $cleanup->run(new ProcessSpec(
                [PHP_BINARY, $root . '/relay/tools/qualification/cleanup.php', '--receipt', $receipt],
                environment: $environment,
                passthrough: true,
                processGroup: true,
                replaceEnvironment: true,
            ));
        } catch (Throwable $failure) {
            $status = $status === 0 ? 1 : $status;
            fwrite(STDERR, 'Registry image cleanup failed: ' . $failure->getMessage() . "\n");
        } finally {
            try {
                $cleanup?->close();
            } catch (Throwable $failure) {
                $status = $status === 0 ? 1 : $status;
                fwrite(STDERR, 'Registry image cleanup executor close failed: ' . $failure->getMessage() . "\n");
            }
        }
        if (file_exists($receipt) || is_link($receipt)) {
            $status = $status === 0 ? 1 : $status;
            fwrite(STDERR, 'Unresolved registry image resources. Retry receipt: ' . $receipt . "\n");
        }
    }
}
exit($status);
