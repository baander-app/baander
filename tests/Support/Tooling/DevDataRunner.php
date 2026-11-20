<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling\Support;

use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevDataRunner extends DevelopmentCommandExecutor
{
    /** @var list<array{arguments:non-empty-list<string>,input:?string,env:?array<string,string>,timeout:?float,check:bool}> */
    public array $calls = [];
    /** @param \Closure(non-empty-list<string>,?string,?array<string,string>):(string|ProcessResult) $callback */
    public function __construct(private readonly \Closure $callback) {}
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
        $this->calls[] = compact('arguments', 'input', 'env', 'timeout', 'check');
        $result = ($this->callback)($arguments, $input, $env);
        return $result instanceof ProcessResult ? $result : self::result($result);
    }
    public static function result(string $stdout = '', int $exitCode = 0): ProcessResult
    {
        return new ProcessResult($exitCode, null, false, false, $stdout, '', 0);
    }
}
