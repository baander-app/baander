<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling\Support;

use Baander\DevEnvironment\State\InstanceStateStore;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\TestCase;

abstract class DevDataTestCase extends TestCase
{
    protected string $root;
    protected InstanceStateStore $store;
    /** @var array<string,mixed> */
    protected array $instance;
    /** @var array<string,mixed> */
    protected array $release;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dev-data-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/source/migrations', 0700, true);
        foreach (['20261017000000', '20261017130000'] as $version) {
            file_put_contents($this->root . '/source/migrations/Version' . $version . '.php', '<?php');
        }
        $this->store = new InstanceStateStore($this->root . '/state');
        $this->instance = [
            'id' => 'persistent',
            'project' => 'fixture-project',
            'kind' => 'persistent',
            'compose_file' => 'compose.json',
            'hostname' => 'dev.baander.app',
            'docker' => DevelopmentCommandExecutor::target('docker', 'fixture', 'daemon'),
        ];
        $this->release = ['source' => $this->root . '/source', 'revision' => 'target'];
        foreach (['postgres', 'redis', 'app', 'worker', 'operator', 'nginx', 'tool'] as $role) {
            $this->release[$role . '_image'] = 'fixture-image';
        }
        $this->release = DevelopmentCommandExecutor::release($this->release, $this->instance);
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
