<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;

$arguments = $_SERVER['argv'];
$store = new StateStore($arguments[1]);
echo $store->load('persistent')['phase'] . "\n";
try {
    $lock = $store->lock('persistent', false);
    $lock->release();
} catch (WorkflowError $error) {
    fwrite(STDERR, $error->getMessage());
    exit(2);
}
