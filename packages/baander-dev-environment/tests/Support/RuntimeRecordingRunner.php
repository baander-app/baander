<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Tests\Support;

use Baander\Tests\Tooling\Support\DevelopmentCommandExecutor;
use Baander\Configuration\ApplicationLayout;
use Baander\DevEnvironment\Runtime\ComposeRuntime;
use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\DevEnvironment\WorkflowError;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\TestCase;

final class RuntimeRecordingRunner extends DevelopmentCommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];
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
        $this->calls[] = $arguments;
        if (in_array('create', $arguments, true) || in_array('start', $arguments, true)
            || in_array('{{.State.ExitCode}}', $arguments, true) || in_array('{{json .State}}', $arguments, true)) {
            return parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        $output = '{}';
        if (in_array('info', $arguments, true)) {
            $output = '{"ID":"daemon"}';
        } elseif (in_array('ps', $arguments, true)) {
            $output = 'nginx-container';
        } elseif (in_array('inspect', $arguments, true) && !in_array('network', $arguments, true)) {
            $output = '{"baander-persistent":{"IPAddress":"172.20.0.3"}}';
        } elseif (in_array('network', $arguments, true) && in_array('inspect', $arguments, true)) {
            $name = $arguments[array_key_last($arguments)];
            $owner = str_starts_with($name, 'baander-development-gateway-') ? 'gateway-' . substr($name, strlen('baander-development-gateway-')) : 'persistent';
            $output = json_encode(
                [[
                    'Labels' => ['app.baander.development.owner' => $owner],
                    'IPAM' => ['Config' => [['Subnet' => '172.20.0.0/16']]],
                ]],
                JSON_THROW_ON_ERROR,
            );
        }
        return new ProcessResult(0, null, false, false, $output, '', 0);
    }
}
