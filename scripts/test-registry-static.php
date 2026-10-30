#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Baander\Registry\Inventory\Inventory;
use Baander\Tooling\Registry\StaticAnalysis\Pins;
use Baander\Tooling\Process\SignalScope;
use Baander\Tooling\Registry\StaticQualification;
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/relay/vendor/autoload.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (!in_array($arguments, [[], ['--pins-notices-only']], true)) {
    fwrite(STDERR, "Usage: test-registry-static.php [--pins-notices-only]\n");
    exit(2);
}

$signals = null;
$status = 0;
try {
    Requirements::check();
    $signals = new SignalScope();
    $root = dirname(__DIR__);
    if (!chdir($root)) {
        throw new RuntimeException('Cannot enter repository root.');
    }
    Pins::check($root);
    $components = Inventory::resolutionData($root . '/relay/third_party/inventory');
    echo Inventory::canonical(['status' => 'passed', 'components' => count($components), 'packages' => 0]);
    if ($arguments === []) {
        StaticQualification::run($root);
    }
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    $status = $failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1;
} finally {
    if ($signals?->receivedSignal() !== null) {
        $status = 128 + $signals->receivedSignal();
    }
    $signals?->close();
}
exit($status);
