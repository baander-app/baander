<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Gateway;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentTopologyTest extends TestCase
{
    public function testPreviewRemovalKeepsPersistentAndCannotReplaceOwnedHostname(): void
    {
        $root = sys_get_temp_dir() . '/baander-topology-' . bin2hex(random_bytes(8));
        try {
            $store = new StateStore($root);
            $runner = new class ($store->scope()) extends Runner {
                public function __construct(private readonly string $scope) {}
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
                    $output = in_array('info', $arguments, true) ? '{"ID":"daemon"}' : (in_array('inspect', $arguments, true)
                        ? json_encode(
                            [[
                                'Labels' => ['app.baander.development.owner' => 'gateway-' . $this->scope],
                                'IPAM' => ['Config' => [['Subnet' => '172.20.0.0/16']]],
                            ]],
                            JSON_THROW_ON_ERROR,
                        ) : '{}');
                    return new ProcessResult(0, null, false, false, $output, '', 0);
                }
            };
            $gateway = new Gateway($store, $runner);
            $instance = [
                'id' => 'persistent',
                'hostname' => 'baander.test',
                'project' => 'persistent-project',
                'docker' => ['executable' => 'fake', 'context' => 'owned', 'daemon_id' => 'daemon'],
            ];
            $gateway->configure($instance, ['nginx_image' => 'nginx']);
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
            new Runner()->run(['rm', '-rf', '--', $root]);
        }
    }
}
