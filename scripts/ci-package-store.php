#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Baander\Tooling\Ci\PackageStore;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, SignalScope};
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

const USAGE = <<<'USAGE'
    Usage: ci-package-store.php <operation> <package> <file> <path>

    Operations on the Forgejo generic package <package>, version latest:
      restore  download the archive <file> and extract it into the empty directory <path>
      save     archive the directory <path> and replace <file> with it
      fetch    download the baseline <file> to <path>
      store    replace the baseline <file> with the file at <path>

    Environment: GITHUB_API_URL, GITHUB_REPOSITORY (<owner>/<name>) and FORGEJO_TOKEN.
    A missing or unreadable package restores an empty directory or fetches nothing, and exits 0.

    USAGE;

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (count($arguments) !== 4 || !in_array($arguments[0], ['restore', 'save', 'fetch', 'store'], true)) {
    fwrite(STDERR, USAGE);
    exit(2);
}
[$operation, $package, $file, $path] = $arguments;

$signals = null;
$executor = null;
$status = 0;
try {
    Requirements::check();
    $signals = new SignalScope();
    $environment = getenv();
    $executor = new EngineExecutor(new ExecutionContext($environment));
    $store = PackageStore::fromEnvironment($executor, $environment);
    echo match ($operation) {
        'restore' => $store->restore($package, $file, $path),
        'save' => $store->save($package, $file, $path),
        'fetch' => $store->fetch($package, $file, $path),
        'store' => $store->store($package, $file, $path),
    } . "\n";
} catch (InvalidArgumentException $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    $status = 2;
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    $status = $failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1;
} finally {
    $executor?->close();
    if ($signals?->receivedSignal() !== null) {
        $status = 128 + $signals->receivedSignal();
    }
    $signals?->close();
}
exit($status);
