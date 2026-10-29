<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Process\ProcessResult;

require_once __DIR__ . '/development-command-executor.php';

final class DevCoreRunner extends DevelopmentCommandExecutor
{
    /** @var list<array{arguments:non-empty-list<string>,env:array<string,string>|null,timeout:float|null,interactive:bool}> */
    public array $calls = [];

    /** @param (\Closure(non-empty-list<string>):?ProcessResult)|null $callback */
    public function __construct(private readonly ?\Closure $callback = null) {}

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
        $this->calls[] = compact('arguments', 'env', 'timeout', 'interactive');
        if ($this->callback !== null) {
            $result = ($this->callback)($arguments);
            if ($result !== null) {
                return $result;
            }
        }
        return new ProcessResult(0, null, false, false, "[main]\ndns=dnsmasq\n", '', 0);
    }
}
