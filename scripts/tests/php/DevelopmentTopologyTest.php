<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Gateway;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\TestCase;

final class DevelopmentTopologyTest extends TestCase
{
    public function testPreviewRemovalKeepsPersistentAndCannotReplaceOwnedHostname(): void
    {
        $root = sys_get_temp_dir() . '/baander-topology-' . bin2hex(random_bytes(8));
        try {
            $store = new StateStore($root);
            PrivateDirectory::prepare($root);
            $executable = $root . '/topology-docker';
            file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\n\$_SERVER['argv'] = [\$_SERVER['argv'][0], "
                . var_export($root, true) . ", ...array_slice(\$_SERVER['argv'], 1)];\nrequire "
                . var_export(__DIR__ . '/Fixtures/activation-gateway-docker.php', true) . ";\n");
            chmod($executable, 0700);
            $runner = new EngineExecutor(ExecutionContext::bounded(120, Environment::clean()));
            $gateway = new Gateway($store, $runner);
            $instance = [
                'id' => 'persistent',
                'hostname' => 'baander.test',
                'project' => 'persistent-project',
                'docker' => DevelopmentCommandExecutor::target($executable, 'owned', 'daemon'),
            ];
            $gateway->configure($instance, DevelopmentCommandExecutor::release(['nginx_image' => 'nginx'], $instance));
            $gateway->route($instance);
            $preview = array_replace(
                $instance,
                [
                    'id' => 'preview-0123456789abcdef',
                    'hostname' => 'feature.baander.test',
                    'project' => 'preview-project',
                ],
            );
            $gateway->route($preview);
            $gateway->remove($preview);
            self::assertStringContainsString('server_name baander.test;', file_get_contents($gateway->configuration));
            self::assertStringNotContainsString('feature.baander.test', file_get_contents($gateway->configuration));
            try {
                $gateway->route(array_replace($preview, ['hostname' => 'baander.test']));
                self::fail('Expected hostname ownership refusal');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('owned by another', $error->getMessage());
            }
        } finally {
            Collector::remove($root);
        }
    }
}
