<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Tests\Support;

use Baander\DevEnvironment\Release\ReleaseBuilder;
use Baander\Configuration\ToolingConfiguration;
use Baander\Tests\Tooling\Support\DevelopmentCommandExecutor;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\WorkflowError;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageRecordingRunner extends DevelopmentCommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];
    public string $frontendExit = '0';
    public bool $interruptCreate = false;
    /** @var list<string> Images the daemon no longer holds. */
    public array $missing = [];
    public function __construct(private readonly string $docker) {}
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
        if ($arguments[0] !== $this->docker) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $this->calls[] = $arguments;
        if ($this->interruptCreate && in_array('create', $arguments, true)) {
            file_put_contents(dirname($this->docker) . '/fail-create', '1');
        }
        if (in_array('create', $arguments, true) || in_array('info', $arguments, true)) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $lost = array_find($this->missing, static fn(string $image): bool => in_array($image, $arguments, true));
        if ($lost !== null && in_array('image', $arguments, true)) {
            return new ProcessResult(1, null, false, false, '', 'Error response from daemon: No such image: ' . $lost, 0);
        }
        if (in_array('image', $arguments, true) && in_array('inspect', $arguments, true) && !in_array('--format', $arguments, true)) {
            return new ProcessResult(0, null, false, false, json_encode([[
                'Id' => 'sha256:' . hash('sha256', implode(' ', $arguments)),
                'Config' => ['Env' => ['APP_HOME=/srv/baander'], 'WorkingDir' => '/srv/baander', 'Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/srv/baander"}']],
            ]], JSON_THROW_ON_ERROR), '', 0);
        }
        $output = in_array('info', $arguments, true) ? '{"ID":"daemon"}' : (in_array('{{.State.ExitCode}}', $arguments, true) ? $this->frontendExit : 'sha256:' . hash('sha256', implode(' ', $arguments)));
        return new ProcessResult(0, null, false, false, $output, '', 0);
    }
}
