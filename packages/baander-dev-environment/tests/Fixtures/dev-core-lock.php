<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/scripts/bootstrap.php';

use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\WorkflowError;

$arguments = $_SERVER['argv'];
$store = new InstanceStateStore($arguments[1]);
echo $store->load('persistent')['phase'] . "\n";
try {
    $lock = $store->lock('persistent', false);
    $lock->release();
} catch (WorkflowError $error) {
    fwrite(STDERR, $error->getMessage());
    exit(2);
}
