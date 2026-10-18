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
    public static function recipe(array $mounts): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe('worker.baander.app', str_repeat('a', 32), 'daemon', 'sha256:' . str_repeat('b', 64), ['/usr/local/bin/php', '/var/www/html/bin/console', 'app:worker'], 'none', 2 * 1024 * 1024 * 1024, 1000000000, 64, DeploymentUnit::Control, [0], 'worker-ipc', mediaMounts: $mounts);
    }
}
