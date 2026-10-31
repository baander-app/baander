<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Web\CapacityWorkflow;
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveCapacityWorkflowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-capacity-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        Collector::remove($this->directory);
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return [
            'version' => 1,
            'containerId' => str_repeat('a', 64),
            'imageId' => 'sha256:' . str_repeat('b', 64),
            'ledger' => $this->directory . '/resources.json',
            'appDirectory' => '/app/baander',
            'baseUrl' => 'https://127.0.0.1:19443',
            'identityPath' => $this->directory . '/capacity-runtime-identity.json',
        ];
    }

    private function target(?string $snapshot = null): Target
    {
        return new Target('/usr/bin/docker', 'unix:///baander.app/docker.sock', 'capacity-daemon', null, $snapshot);
    }

    public function testSourceIdentityTracksBackendConfigurationAndPackageContentWithoutSecrets(): void
    {
        foreach (['src', 'config/secrets', 'migrations', 'packages/example/src'] as $path) {
            mkdir($this->directory . '/' . $path, 0700, true);
        }
        file_put_contents($this->directory . '/composer.lock', '{}');
        file_put_contents($this->directory . '/src/Server.php', '<?php // server');
        file_put_contents($this->directory . '/config/app.yaml', 'debug: false');
        file_put_contents($this->directory . '/packages/example/src/Runtime.php', '<?php // runtime');
        $initial = CapacityWorkflow::sourceDigest($this->directory);
        file_put_contents($this->directory . '/config/secrets/private.key', 'test-only-secret');
        self::assertSame($initial, CapacityWorkflow::sourceDigest($this->directory));
        file_put_contents($this->directory . '/config/app.yaml', 'debug: true');
        $configurationChanged = CapacityWorkflow::sourceDigest($this->directory);
        self::assertNotSame($initial, $configurationChanged);
        file_put_contents($this->directory . '/packages/example/src/Runtime.php', '<?php // changed runtime');
        self::assertNotSame($configurationChanged, CapacityWorkflow::sourceDigest($this->directory));
    }

    public function testSymfonyGeneratedReferenceIsExcludedWithoutExcludingAuthoredReferences(): void
    {
        foreach (['src', 'config', 'migrations', 'packages/example/src'] as $path) {
            mkdir($this->directory . '/' . $path, 0700, true);
        }
        file_put_contents($this->directory . '/composer.lock', '{}');
        file_put_contents($this->directory . '/config/reference.php', '<?php // generated before cache compilation');
        $initial = CapacityWorkflow::sourceDigest($this->directory);
        file_put_contents($this->directory . '/config/reference.php', '<?php // generated after cache compilation');
        self::assertSame($initial, CapacityWorkflow::sourceDigest($this->directory));
        file_put_contents($this->directory . '/config/authored-reference.php', '<?php // authored configuration');
        $authored = CapacityWorkflow::sourceDigest($this->directory);
        self::assertNotSame($initial, $authored);
        file_put_contents($this->directory . '/packages/example/src/reference.php', '<?php // authored package source');
        self::assertNotSame($authored, CapacityWorkflow::sourceDigest($this->directory));
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function ownership(): array
    {
        $manifest = $this->manifest();
        $token = str_repeat('c', 32);
        return [[
            'version' => 1,
            'token' => $token,
            'target' => $this->target()->toArray(),
            'resources' => [['kind' => 'container', 'name' => 'baander-capacity-app', 'id' => $manifest['containerId']]],
        ], [
            'Id' => $manifest['containerId'],
            'Image' => $manifest['imageId'],
            'Config' => ['Labels' => ['org.baander.tooling.run' => $token]],
            'State' => ['Running' => true],
        ]];
    }

    public function testPrivateManifestAcceptsJsonKeyOrderAndContainsNoExecutable(): void
    {
        $path = $this->directory . '/capacity-manifest.json';
        AtomicFile::writeJson($path, array_reverse($this->manifest(), true));
        self::assertEquals($this->manifest(), CapacityWorkflow::readManifest($path));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidManifests(): iterable
    {
        yield 'executable injection' => ['executable'];
        yield 'public permissions' => ['permissions'];
        yield 'different ledger directory' => ['ledger'];
        yield 'non-loopback origin' => ['origin'];
        yield 'symlink manifest' => ['symlink'];
        yield 'public directory' => ['directory'];
    }

    #[DataProvider('invalidManifests')]
    public function testRejectsUnownedOrExecutableManifest(string $variation): void
    {
        $manifest = $this->manifest();
        if ($variation === 'executable') {
            $manifest['executable'] = '/bin/false';
        } elseif ($variation === 'ledger') {
            $manifest['ledger'] = '/tmp/foreign/resources.json';
        } elseif ($variation === 'origin') {
            $manifest['baseUrl'] = 'https://baander.app';
        }
        $path = $this->directory . '/capacity-manifest.json';
        AtomicFile::writeJson($path, $manifest);
        if ($variation === 'permissions') {
            chmod($path, 0644);
        } elseif ($variation === 'symlink') {
            rename($path, $this->directory . '/saved.json');
            symlink($this->directory . '/saved.json', $path);
        } elseif ($variation === 'directory') {
            chmod($this->directory, 0755);
        }
        $this->expectException(\RuntimeException::class);
        CapacityWorkflow::readManifest($path);
    }

    public function testOwnedFixtureCommandIsFixedAndUsesSelectedImageLayout(): void
    {
        [$ledger, $inspection] = $this->ownership();
        self::assertSame([
            'exec', '-i', '-e', 'BAANDER_LIVE_CAPACITY_API_ORIGIN=http://127.0.0.1:9501',
            '-w', '/app/baander', str_repeat('a', 64),
            'php', 'tests/Fixtures/LiveUpdates/capacity-fixture.php',
        ], CapacityWorkflow::fixtureCommand($this->manifest(), $ledger, $inspection, $this->target(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/app/baander'])));
    }

    public function testIndependentTransportSnapshotsDoNotChangeTheSelectedDaemon(): void
    {
        [$ledger, $inspection] = $this->ownership();
        mkdir($this->directory . '/parent', 0700);
        mkdir($this->directory . '/child', 0700);
        $ledger['target'] = $this->target($this->directory . '/parent')->toArray();
        $command = CapacityWorkflow::fixtureCommand($this->manifest(), $ledger, $inspection, $this->target($this->directory . '/child'), ApplicationLayout::fromArray(['version' => 1, 'root' => '/app/baander']));
        self::assertSame('tests/Fixtures/LiveUpdates/capacity-fixture.php', $command[array_key_last($command)]);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidOwnership(): iterable
    {
        yield 'daemon changed' => ['daemon'];
        yield 'Docker executable changed' => ['executable'];
        yield 'container replaced' => ['container'];
        yield 'image replaced' => ['image'];
        yield 'ownership label replaced' => ['label'];
        yield 'container absent from ledger' => ['ledger'];
        yield 'stopped container' => ['stopped'];
        yield 'arbitrary fixture directory' => ['directory'];
    }

    #[DataProvider('invalidOwnership')]
    public function testRejectsChangedTargetAndContainerOwnership(string $variation): void
    {
        [$ledger, $inspection] = $this->ownership();
        $manifest = $this->manifest();
        match ($variation) {
            'daemon' => $ledger['target']['daemonId'] = 'foreign-daemon',
            'executable' => $ledger['target']['executable'] = '/bin/false',
            'container' => $inspection['Id'] = str_repeat('d', 64),
            'image' => $inspection['Image'] = 'sha256:' . str_repeat('d', 64),
            'label' => $inspection['Config']['Labels']['org.baander.tooling.run'] = 'foreign-token',
            'ledger' => $ledger['resources'] = [],
            'stopped' => $inspection['State']['Running'] = false,
            'directory' => $manifest['appDirectory'] = '/tmp/foreign',
            default => throw new \InvalidArgumentException('Unknown ownership variation: ' . $variation),
        };
        $this->expectException(\RuntimeException::class);
        CapacityWorkflow::fixtureCommand($manifest, $ledger, $inspection, $this->target(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/app/baander']));
    }
}
