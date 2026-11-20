<?php

declare(strict_types=1);

use TypephpProcessCacheFixture\CallableConstants;
use TypephpProcessCacheFixture\ChildValue;
use TypephpProcessCacheFixture\ColdCallableConstants;
use TypephpProcessCacheFixture\ParentValue;
use TypephpProcessCacheFixture\Value;

use function TypephpProcessCacheFixture\identity;

require __DIR__.'/fixtures.php';

$check = static function (string $phase): void {
    for ($index = 0; $index < 20; ++$index) {
        $argument = $phase.':'.$index;
        if ((CallableConstants::GLOBAL_CALL)($argument) !== 'global:'.$argument
            || (CallableConstants::STATIC_CALL)($argument) !== 'static:'.$argument) {
            throw new RuntimeException('Callable result mismatch in '.$phase);
        }
    }
    $value = new ChildValue();
    if (!$value instanceof Value
        || identity($value) !== $value
        || get_parent_class($value) !== ParentValue::class) {
        throw new RuntimeException('Class or interface identity mismatch in '.$phase);
    }
};

$checkCold = static function (string $phase): void {
    for ($index = 0; $index < 20; ++$index) {
        $argument = $phase.':'.$index;
        if ((ColdCallableConstants::GLOBAL_CALL)($argument) !== 'cold-global:'.$argument
            || (ColdCallableConstants::STATIC_CALL)($argument) !== 'cold-static:'.$argument) {
            throw new RuntimeException('Deferred callable result mismatch in '.$phase);
        }
    }
};

$check('before-fork');
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('Cannot fork process-cache fixture child.');
}
if ($pid === 0) {
    $check('child');
    $checkCold('child-first-resolution');
    echo json_encode(['phase' => 'child', 'callable_checks' => 80, 'identity_checks' => 3], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$check('parent-after-fork');
if (pcntl_waitpid($pid, $status) !== $pid || pcntl_wifsignaled($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('Process-cache fixture child failed.');
}
$check('parent-after-child');
$checkCold('parent-first-resolution');
echo json_encode(['phase' => 'parent', 'callable_checks' => 160, 'identity_checks' => 9, 'child_exit' => 0], JSON_THROW_ON_ERROR), "\n";
