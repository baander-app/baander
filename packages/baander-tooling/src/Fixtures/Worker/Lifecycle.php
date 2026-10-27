<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;

final class Lifecycle
{
    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        Runtime::require($arguments === [], 'Worker lifecycle qualification takes no arguments');
        $runtime = new Runtime($root, 1300);
        $artifacts = Runtime::artifacts($root, 'worker-lifecycle');
        try {
            foreach (['redis', 'postgres'] as $phase) {
                $runtime->run(
                    [PHP_BINARY, 'scripts/test-worker-operator-container.php'],
                    env: array_replace(
                        $runtime->environment,
                        [
                            'BAANDER_TEST_ARTIFACTS' => $artifacts . '/' . $phase,
                            'BAANDER_TEST_WORKER_LIFECYCLE_PHASE' => $phase,
                            'BAANDER_TEST_AUTOSCALING_MAXIMUM' => '2',
                            'BAANDER_TEST_TWO_COMMAND' => '0',
                            'BAANDER_TEST_OPERATOR_IN_CONTAINER' => $runtime->environment['BAANDER_TEST_OPERATOR_IN_CONTAINER'] ?? '1',
                        ],
                    ),
                    timeout: null,
                    logPath: $artifacts . '/' . $phase . '.log',
                    terminationGrace: 40,
                );
            }
            foreach (['redis', 'postgres'] as $phase) {
                $result = Runtime::json($artifacts . '/' . $phase . '/qualification.json');
                $cleanup = Runtime::json($artifacts . '/' . $phase . '/cleanup.json');
                Runtime::require(
                    ($result['phase'] ?? null) === $phase && ($result['success'] ?? false) === true,
                    'Lifecycle phase failed: ' . $phase,
                );
                Runtime::require(
                    ($cleanup['exitStatus'] ?? null) === 0 && ($cleanup['cleanupVerified'] ?? false) === true,
                    'Lifecycle cleanup failed: ' . $phase,
                );
            }
            echo "PASS: standalone Redis and PostgreSQL outage, retirement and successor checks; cleanup verified.\nWorker lifecycle evidence: ", $artifacts, "\n";
            return 0;
        } finally {
            $runtime->close();
        }
    }
}
