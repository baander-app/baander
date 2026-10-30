<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';
$workflow = new Baander\Tooling\Registry\ContainerQualification($_SERVER['argv'][1]);
if (getenv('REGISTRY_TEST_CLOSE_FAILURE') === '1') {
    $executor = new ReflectionProperty($workflow, 'runner')->getValue($workflow);
    $runner = new ReflectionProperty($executor, 'runner')->getValue($executor);
    $handle = new class {
        public function pump(): void {}
        public function result(): null
        {
            return null;
        }
        public function ignoresCancellation(): bool
        {
            return true;
        }
        public function dispose(): never
        {
            throw new RuntimeException('injected executor close failure', 71);
        }
    };
    new ReflectionProperty($runner, 'processes')->setValue($runner, [spl_object_id($handle) => $handle]);
}
exit($workflow->run($_SERVER['argv'][2] === 'backup'));
