<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Tests\Support;

use Baander\DevEnvironment\Release\ReleaseBuilder;
use Baander\Images\Build\SourceUnavailable;
use Baander\Tests\Tooling\Support\DevelopmentCommandExecutor;
use Baander\DevEnvironment\Runtime\ComposeRuntime;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\Release\Updater;
use Baander\DevEnvironment\Worker\WorkerDeployment;
use Baander\DevEnvironment\WorkflowError;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

/** Answers the daemon's image lookups, so no test reaches a real Docker daemon. */
final class DaemonImagesExecutor extends DevelopmentCommandExecutor
{
    /** @var list<string> Images the daemon no longer holds. */
    public array $missing = [];
    /** @var list<string> */
    public array $inspected = [];

    public function execute(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        if (array_slice($arguments, 3, 2) !== ['image', 'inspect']) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $this->inspected[] = $image = end($arguments);
        return in_array($image, $this->missing, true)
            ? new ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $image, 0)
            : new ProcessResult(0, null, false, false, $image, '', 0);
    }
}
