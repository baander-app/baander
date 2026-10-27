<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Host;
use Baander\Tooling\Development\Gateway;
use Baander\Tooling\Development\HostSystem;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dev-core-runner.php';

final class DevelopmentHostTest extends TestCase
{
    private string $root;
    private StateStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dev-host-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->store = new StateStore($this->root . '/state');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function system(bool $selinux = false, bool $occupied = false): HostSystem
    {
        return new class ($selinux, $occupied) extends HostSystem {
            public function __construct(private bool $selinux, private bool $occupied) {}
            public function executable(string $name): string
            {
                return '/fake/' . $name;
            }
            public function selinuxEnabled(): bool
            {
                return $this->selinux;
            }
            public function resolverOwned(): bool
            {
                return true;
            }
            public function assertPortAvailable(): void
            {
                if ($this->occupied) {
                    throw new WorkflowError('Loopback port 443 is occupied; stop its owner before first gateway startup.');
                }
            }
        };
    }

    private function runner(): DevCoreRunner
    {
        return new DevCoreRunner(static function (array $arguments): ?ProcessResult {
            if ($arguments[0] === 'mkcert' && in_array('-key-file', $arguments, true)) {
                file_put_contents($arguments[array_search('-key-file', $arguments, true) + 1], 'private-key');
                file_put_contents($arguments[array_search('-cert-file', $arguments, true) + 1], 'certificate');
            }
            return null;
        });
    }

    public function testInspectionChecksHostWithoutCreatingState(): void
    {
        self::assertSame(
            ['resolver' => 'NetworkManager', 'port' => 443, 'hostname' => 'baander.test'],
            new Host($this->store, $this->runner(), $this->system())->inspect(),
        );
        self::assertDirectoryDoesNotExist($this->store->root);
    }

    public function testPrivilegedPortWithoutBindPermissionIsAvailableWhenConnectionIsRefused(): void
    {
        $system = new class extends HostSystem {
            protected function bindPort(): int
            {
                return 13;
            }
            protected function connectPort(): int
            {
                return 111;
            }
        };
        $system->assertPortAvailable();
        $this->addToAssertionCount(1);
    }

    public function testPrivilegedPortWithExistingListenerIsRefused(): void
    {
        $system = new class extends HostSystem {
            protected function bindPort(): int
            {
                return 13;
            }
            protected function connectPort(): int
            {
                return 0;
            }
        };
        $this->expectExceptionMessage('occupied');
        $system->assertPortAvailable();
    }

    public function testAddressInUseIsRefusedWithoutFallbackConnection(): void
    {
        $system = new class extends HostSystem {
            protected function bindPort(): int
            {
                return 98;
            }
            protected function connectPort(): int
            {
                throw new \LogicException('Unexpected connection probe');
            }
        };
        $this->expectExceptionMessage('occupied');
        $system->assertPortAvailable();
    }

    public function testOccupiedPortRefusesBeforeMutation(): void
    {
        try {
            new Host($this->store, $this->runner(), $this->system(occupied: true))->inspect();
            self::fail('Occupied port accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('occupied', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->store->root);
        }
    }

    public function testSelectedPathsExcludePrivateCaAndParentsEvenWithoutSelinux(): void
    {
        mkdir($this->store->root . '/host-setup/ca', 0700, true);
        $runner = $this->runner();
        foreach ([$this->store->root . '/host-setup/ca', $this->store->root, $this->root] as $path) {
            try {
                new Host($this->store, $runner, $this->system())->sharePaths([$path]);
                self::fail('CA exposing path accepted');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('private certificate authority', $error->getMessage());
            }
        }
        self::assertSame([], $runner->calls);
    }

    public function testSelinuxRelabelingIsLimitedAndPrivateCaSurvives(): void
    {
        mkdir($this->store->root . '/host-setup/ca', 0700, true);
        file_put_contents($this->store->root . '/host-setup/ca/rootCA-key.pem', 'private-ca');
        mkdir($this->root . '/checkout');
        $this->store->save('persistent', ['id' => 'persistent']);
        $runner = $this->runner();
        new Host($this->store, $runner, $this->system(selinux: true))->sharePaths([$this->root . '/checkout']);
        $recursive = [];
        foreach ($runner->calls as $call) {
            if (in_array('-R', $call['arguments'], true)) {
                self::assertNull($call['timeout']);
                self::assertContains('--no-dereference', $call['arguments']);
                $recursive[] = $call['arguments'][array_key_last($call['arguments'])];
            }
        }
        self::assertEqualsCanonicalizing(
            [$this->root . '/checkout', $this->store->root . '/gateway', $this->store->root . '/persistent'],
            $recursive,
        );
        self::assertSame('private-ca', file_get_contents($this->store->root . '/host-setup/ca/rootCA-key.pem'));
    }

    public function testSymlinkInstanceCannotBeRelabeled(): void
    {
        mkdir($this->root . '/checkout');
        mkdir($this->store->root);
        symlink($this->root . '/checkout', $this->store->root . '/persistent');
        $this->expectException(WorkflowError::class);
        new Host($this->store, $this->runner(), $this->system(selinux: true))->sharePaths([$this->root . '/checkout']);
    }

    public function testCertificateTrustIsExplicitAndKeysArePrivate(): void
    {
        $runner = $this->runner();
        $host = new Host($this->store, $runner, $this->system());
        $directory = $host->certificates();
        self::assertCount(1, $runner->calls);
        self::assertSame(['CAROOT' => $this->store->root . '/host-setup/ca'], $runner->calls[0]['env']);
        self::assertSame(0600, fileperms($directory . '/key.pem') & 0777);
        self::assertSame(0700, fileperms($this->store->root . '/host-setup/ca') & 0777);
        $host->certificates(true);
        self::assertSame(['mkcert', '-install'], $runner->calls[1]['arguments']);
        self::assertTrue($runner->calls[1]['interactive']);
    }

    public function testHostInstructionsAreCopiedWithoutApplicationBootstrap(): void
    {
        $directory = new Host($this->store, $this->runner(), $this->system())->instructions();
        self::assertFileEquals(
            dirname(__DIR__, 3) . '/docker/dev/host-setup/10-baander-dns.conf',
            $directory . '/10-baander-dns.conf',
        );
    }

    public function testGatewayOwnsNetworkAndRestoresConfigurationWhenReloadFails(): void
    {
        $failureFlag = $this->root . '/reload-fails';
        $runner = new DevCoreRunner(function (array $arguments) use ($failureFlag): ProcessResult {
            $output = '';
            if (in_array('info', $arguments, true)) {
                $output = '{"ID":"daemon"}';
            } elseif (in_array('inspect', $arguments, true)) {
                $output = json_encode(
                    [[
                        'Labels' => ['app.baander.development.owner' => 'gateway-' . $this->store->scope()],
                        'IPAM' => ['Config' => [['Subnet' => '172.29.20.0/24']]],
                    ]],
                    JSON_THROW_ON_ERROR,
                );
            } elseif (is_file($failureFlag) && in_array('reload', $arguments, true)) {
                throw new WorkflowError('reload failed');
            }
            return new ProcessResult(0, null, false, false, $output, '', 0);
        });
        $gateway = new Gateway($this->store, $runner);
        $instance = [
            'id' => 'persistent',
            'hostname' => 'baander.test',
            'project' => 'owned',
            'docker' => ['executable' => '/fake/docker', 'context' => 'selected', 'daemon_id' => 'daemon'],
        ];
        $network = $gateway->configure($instance, ['nginx_image' => 'nginx@sha256:original']);
        self::assertSame('172.29.20.2', $network['ipv4_address']);
        $gateway->route($instance);
        $original = file_get_contents($gateway->configuration);
        $routes = file_get_contents($gateway->directory . '/routes.json');
        touch($failureFlag);
        try {
            $gateway->route($instance, true);
            self::fail('Reload failure ignored');
        } catch (WorkflowError) {
            self::assertSame($original, file_get_contents($gateway->configuration));
            self::assertSame($routes, file_get_contents($gateway->directory . '/routes.json'));
        }
        unlink($failureFlag);
        $gateway->remove($instance);
        self::assertSame('{}', trim((string) file_get_contents($gateway->directory . '/routes.json')));
        $gateway->configure($instance, ['nginx_image' => 'nginx@sha256:new']);
        self::assertSame('nginx@sha256:original', $gateway->record()['nginx_image']);
    }

    public function testGatewayRejectsForeignNetworkAndInvalidRoute(): void
    {
        $gateway = new Gateway($this->store, $this->runner());
        try {
            $gateway->render(['foreign.baander.app' => ['upstream' => 'owned-frontend', 'maintenance' => false]]);
            self::fail('Foreign hostname accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('hostname', $error->getMessage());
        }
        $runner = new DevCoreRunner(static fn(array $arguments): ProcessResult => new ProcessResult(
            0,
            null,
            false,
            false,
            '[{"Labels":{"app.baander.development.owner":"foreign"},"IPAM":{"Config":[{"Subnet":"172.20.0.0/24"}]}}]',
            '',
            0,
        ));
        $this->expectExceptionMessage('not owned');
        Gateway::ownedNetwork(
            new \Baander\Tooling\Development\Docker($runner, ['executable' => '/fake/docker', 'context' => 'selected']),
            'network',
            'owned',
        );
    }
}
