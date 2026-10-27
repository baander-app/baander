<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevDataRunner extends Runner
{
    /** @var list<array{arguments:non-empty-list<string>,input:?string,env:?array<string,string>,timeout:?float,check:bool}> */
    public array $calls = [];
    /** @param \Closure(non-empty-list<string>,?string,?array<string,string>):(string|ProcessResult) $callback */
    public function __construct(private readonly \Closure $callback) {}
    public function run(
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

abstract class DevDataTestCase extends TestCase
{
    protected string $root;
    protected StateStore $store;
    /** @var array<string,mixed> */
    protected array $instance;
    /** @var array<string,mixed> */
    protected array $release;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dev-data-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/migrations');
        foreach (['20261017000000', '20261017130000'] as $version) {
            file_put_contents($this->root . '/migrations/Version' . $version . '.php', '<?php');
        }
        $this->store = new StateStore($this->root . '/state');
        $this->instance = [
            'id' => 'persistent',
            'project' => 'fixture-project',
            'kind' => 'persistent',
            'compose_file' => 'compose.json',
            'hostname' => 'dev.baander.app',
            'docker' => ['executable' => 'docker', 'context' => 'fixture', 'daemon_id' => 'daemon'],
        ];
        $this->release = ['source' => $this->root, 'revision' => 'target'];
        foreach (['postgres', 'redis', 'app', 'worker', 'operator', 'nginx', 'tool'] as $role) {
            $this->release[$role . '_image'] = 'fixture-image';
        }
    }
    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }
    /** @param array<string,mixed> $catalog */
    protected function catalogRunner(array $catalog): DevDataRunner
    {
        return new DevDataRunner(static function (array $arguments) use ($catalog): string {
            if (in_array('info', $arguments, true)) {
                return '{"ID":"daemon"}';
            }
            if (str_contains(implode(' ', $arguments), 'psql')) {
                return json_encode($catalog, JSON_THROW_ON_ERROR);
            }
            return 'container';
        });
    }
}
