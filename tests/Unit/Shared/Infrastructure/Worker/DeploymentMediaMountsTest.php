<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;

use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;
use InvalidArgumentException;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentMediaMountsTest extends TestCase
{
    public function testAuthorizedMountsProduceExplicitModesAndStableIdentity(): void
    {
        $mounts = self::mounts();
        $recipe = self::recipe($mounts);
        self::assertContains('type=volume,src=baander-source,dst=/media/library,readonly,volume-nocopy', $recipe->createArguments());
        self::assertContains('type=volume,src=baander-derived,dst=/var/www/html/storage,volume-nocopy', $recipe->createArguments());
        self::assertSame($recipe->fingerprint(), self::recipe(array_reverse($mounts))->fingerprint());
        $mounts[0]['volume'] = 'baander-replacement';
        self::assertNotSame($recipe->fingerprint(), self::recipe($mounts)->fingerprint());
        $mounts = self::mounts();
        $mounts[1]['role'] = 'source';
        $mounts[1]['readOnly'] = true;
        self::assertNotSame($recipe->fingerprint(), self::recipe($mounts)->fingerprint());
        $mounts = self::mounts();
        $mounts[1]['readOnly'] = true;
        $this->expectException(InvalidArgumentException::class);
        self::recipe($mounts);
    }

    public function testDevelopmentBindContainmentChecksExactReadOnlySource(): void
    {
        $mounts = self::mounts();
        $mounts[0] = ['source' => '/tmp', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'];
        $recipe = self::recipe($mounts, 'local-development');
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $arguments) use (&$calls): string {
            $calls[] = $arguments;
            $inspection = self::inspection();
            $inspection['mounts'][0] = ['Type' => 'bind', 'Source' => '/tmp', 'Destination' => '/media/library', 'RW' => false, 'Propagation' => 'rprivate'];
            return json_encode($arguments[0] === 'container' ? $inspection : self::volume($arguments[4]), JSON_THROW_ON_ERROR);
        }, $recipe);
        $containment->verifyIsolation(str_repeat('c', 64), 'worker.baander.app', str_repeat('a', 32), DeploymentUnit::Control);
        self::assertCount(3, $calls);
        foreach (['Source' => '/var/tmp', 'RW' => true, 'Type' => 'volume', 'Propagation' => 'rshared'] as $key => $value) {
            $rejected = false;
            $containment = new DockerWorkerContainment(static function (array $arguments) use ($key, $value): string {
                $inspection = self::inspection();
                $inspection['mounts'][0] = ['Type' => 'bind', 'Source' => '/tmp', 'Destination' => '/media/library', 'RW' => false, 'Propagation' => 'rprivate'];
                $inspection['mounts'][0][$key] = $value;
                return json_encode($arguments[0] === 'container' ? $inspection : self::volume($arguments[4]), JSON_THROW_ON_ERROR);
            }, $recipe);
            try {
                $containment->verifyIsolation(str_repeat('c', 64), 'worker.baander.app', str_repeat('a', 32), DeploymentUnit::Control);
            } catch (RuntimeException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Bind substitution accepted: ' . $key);
        }
    }

    public function testDevelopmentBindRejectsWritableDerivedTraversalAndSymlinkSources(): void
    {
        $alias = '/tmp/baander-source-alias-' . bin2hex(random_bytes(8));
        self::assertTrue(symlink('/tmp', $alias));
        try {
            foreach ([['source' => '/tmp', 'readOnly' => false], ['source' => '/tmp', 'role' => 'derived', 'readOnly' => false],
                ['source' => '/tmp/../tmp'], ['source' => '/tmp/'], ['source' => $alias], ['source' => '/does-not-exist.baander.app']] as $changes) {
                $rejected = false;
                try {
                    self::recipe([array_replace(['source' => '/tmp', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'], $changes)], 'local-development');
                } catch (InvalidArgumentException) {
                    $rejected = true;
                }
                self::assertTrue($rejected);
            }
        } finally {
            unlink($alias);
        }
    }

    /** @param list<array<string,mixed>> $mounts */
    #[DataProvider('unsafeMounts')]
    public function testRejectsUnsafeTopology(array $mounts): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::recipe($mounts);
    }

    /** @return iterable<string,array{list<array<string,mixed>>}> */
    public static function unsafeMounts(): iterable
    {
        foreach ([
            'writable source' => ['readOnly' => false],
            'host path source' => ['volume' => '/srv/media'],
            'socket' => ['target' => '/var/run/docker.sock'],
            'code' => ['target' => '/var/www/html/src'],
            'root' => ['target' => '/'],
            'traversal' => ['target' => '/media/../etc'],
            'driver option' => ['driverOptions' => ['device' => '/srv/media']],
            'bind type' => ['type' => 'bind'],
        ] as $label => $changes) {
            yield $label => [[array_replace(self::mounts()[0], $changes)]];
        }
        yield 'media aliases IPC volume' => [[['volume' => 'worker-ipc', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source']]];
        yield 'media shadows IPC path' => [[['volume' => 'media', 'target' => '/run/baander-worker', 'readOnly' => true, 'role' => 'source']]];
        yield 'overlapping targets' => [[...self::mounts(), ['volume' => 'nested', 'target' => '/media/library/nested', 'readOnly' => true, 'role' => 'source']]];
        yield 'same volume writable alias' => [[...self::mounts(), ['volume' => 'baander-source', 'target' => '/media/alias', 'readOnly' => false, 'role' => 'derived']]];
    }

    public function testIsolationMatchesEveryVolumeAndChecksLocalStorageBeforeAdmission(): void
    {
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $arguments) use (&$calls): string {
            $calls[] = $arguments;
            return json_encode($arguments[0] === 'container' ? self::inspection() : self::volume($arguments[4]), JSON_THROW_ON_ERROR);
        }, self::recipe(self::mounts()));
        $containment->verifyIsolation(str_repeat('c', 64), 'worker.baander.app', str_repeat('a', 32), DeploymentUnit::Control);
        self::assertCount(4, $calls);
        self::assertSame(['volume', 'inspect'], array_slice($calls[1], 0, 2));
        self::assertSame(['volume', 'inspect'], array_slice($calls[2], 0, 2));
    }

    #[DataProvider('mismatchedStorage')]
    public function testIsolationRejectsUnadmittedMountsAndUnsafeLocalVolumes(string $kind): void
    {
        $containment = new DockerWorkerContainment(static function (array $arguments) use ($kind): string {
            $inspection = self::inspection();
            if ($arguments[0] === 'container') {
                match ($kind) {
                    'missing IPC mount' => array_pop($inspection['mounts']),
                    'read-only IPC mount' => $inspection['mounts'][2]['RW'] = false,
                    'wrong IPC volume' => $inspection['mounts'][2]['Name'] = 'foreign-ipc',
                    'wrong memory ceiling' => $inspection['memory'] = 1,
                    'unlimited swap' => $inspection['memorySwap'] = -1,
                    'wrong CPU ceiling' => $inspection['nanoCpus'] = 0,
                    'wrong PID ceiling' => $inspection['pidsLimit'] = 0,
                    'wrong CPU set' => $inspection['cpuSet'] = '1',
                    'writable source' => $inspection['mounts'][0]['RW'] = true,
                    'different volume' => $inspection['mounts'][0]['Name'] = 'unlisted',
                    'bind mount' => $inspection['mounts'][0]['Type'] = 'bind',
                    'missing mount' => array_pop($inspection['mounts']),
                    'extra mount' => $inspection['mounts'][] = $inspection['mounts'][0],
                    'different target' => $inspection['mounts'][0]['Destination'] = '/media/other',
                    'source substitution' => $inspection['mounts'][0]['Source'] = '/srv/other',
                    default => null,
                };
                return json_encode($inspection, JSON_THROW_ON_ERROR);
            }
            $volume = self::volume($arguments[4]);
            match ($kind) {
                'driver options' => $volume['options'] = ['type' => 'none', 'o' => 'bind', 'device' => '/srv/source'],
                'remote driver' => $volume['driver'] = 'nfs',
                'global volume' => $volume['scope'] = 'global',
                default => null,
            };
            return json_encode($volume, JSON_THROW_ON_ERROR);
        }, self::recipe(self::mounts()));
        $this->expectException(RuntimeException::class);
        $containment->verifyIsolation(str_repeat('c', 64), 'worker.baander.app', str_repeat('a', 32), DeploymentUnit::Control);
    }

    /** @return iterable<string,array{string}> */
    public static function mismatchedStorage(): iterable
    {
        foreach (['missing IPC mount', 'read-only IPC mount', 'wrong IPC volume', 'wrong memory ceiling', 'unlimited swap', 'wrong CPU ceiling', 'wrong PID ceiling', 'wrong CPU set', 'writable source', 'different volume', 'bind mount', 'missing mount', 'extra mount', 'different target', 'source substitution', 'driver options', 'remote driver', 'global volume'] as $kind) {
            yield $kind => [$kind];
        }
    }

    /** @return array<string,mixed> */
    private static function inspection(): array
    {
        return [
            'id' => str_repeat('c', 64), 'namespace' => 'worker.baander.app', 'bootId' => str_repeat('a', 32),
            'role' => 'control', 'memory' => 2 * 1024 * 1024 * 1024, 'memorySwap' => 2 * 1024 * 1024 * 1024,
            'nanoCpus' => 1000000000, 'pidsLimit' => 64, 'cpuSet' => '0', 'restart' => 'no', 'retryCount' => 0, 'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private',
            'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges'],
            'binds' => null, 'tmpfs' => null, 'devices' => null, 'deviceRequests' => null, 'capAdd' => null,
            'mounts' => array_map(static fn (array $mount): array => [
                'Type' => 'volume', 'Name' => $mount['volume'], 'Driver' => 'local',
                'Source' => '/var/lib/docker/volumes/' . $mount['volume'] . '/_data',
                'Destination' => $mount['target'], 'RW' => !$mount['readOnly'],
            ], [...self::mounts(), ['volume' => 'worker-ipc', 'target' => '/run/baander-worker', 'readOnly' => false, 'role' => 'ipc']]),
        ];
    }

    /** @return array{name:string,driver:string,options:null,scope:string,mountpoint:string} */
    private static function volume(string $name): array
    {
        return ['name' => $name, 'driver' => 'local', 'options' => null, 'scope' => 'local',
            'mountpoint' => '/var/lib/docker/volumes/' . $name . '/_data'];
    }

    /** @return list<array{volume:string,target:string,readOnly:bool,role:string}> */
    public static function mounts(): array
    {
        return [
            ['volume' => 'baander-source', 'target' => '/media/library', 'readOnly' => true, 'role' => 'source'],
            ['volume' => 'baander-derived', 'target' => '/var/www/html/storage', 'readOnly' => false, 'role' => 'derived'],
        ];
    }

    /** @param list<array<string,mixed>> $mounts */
    public static function recipe(array $mounts, string $sourceMountPolicy = 'production'): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('worker.baander.app', str_repeat('a', 32), 'daemon', 'sha256:' . str_repeat('b', 64), ['/usr/local/bin/php', '/var/www/html/bin/console', 'app:worker'], 'none', 2 * 1024 * 1024 * 1024, 1000000000, 64, DeploymentUnit::Control, [0], 'worker-ipc', mediaMounts: $mounts, sourceMountPolicy: $sourceMountPolicy);
    }
}
