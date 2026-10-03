<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DockerWorkerContainmentTest extends TestCase
{
    private const string ID = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string BOOT = '0123456789abcdef0123456789abcdef';

    public function testMatchingSafeDeploymentRequiresSuccessfulExactIdRemoval(): void
    {
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            return count($calls) === 1 ? json_encode(self::inspection(), JSON_THROW_ON_ERROR) : self::ID . "\n";
        });
        $containment->retire(self::ID, $this->lease());
        self::assertCount(2, $calls);
        self::assertSame(['container', 'inspect', '--format'], array_slice($calls[0], 0, 3));
        self::assertSame(self::ID, $calls[0][4]);
        self::assertStringContainsString('app.baander.worker.role', $calls[0][3]);
        self::assertStringNotContainsString('{{json .}}', $calls[0][3], 'Inspection must not expose the full environment or configuration.');
        self::assertSame(['container', 'rm', '--force', self::ID], $calls[1]);
    }

    public function testPrestartIsolationVerificationInspectsWithoutRemovalOrLease(): void
    {
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            self::assertSame('inspect', $argv[1], 'Verification cannot start or remove a container.');
            return json_encode(self::inspection(), JSON_THROW_ON_ERROR);
        });
        $containment->verifyIsolation(self::ID, 'worker.baander.app', self::BOOT);
        self::assertCount(1, $calls);
        self::assertSame(self::ID, $calls[0][4]);
    }

    public function testDurableRemovalReconciliationAcceptsSuccessfulEmptyListing(): void
    {
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            return '';
        });
        $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
        self::assertSame([[
            'container', 'ls', '--all', '--no-trunc', '--filter', 'id=' . self::ID, '--format', '{{.ID}}',
        ]], $calls);
    }

    public function testDurableRemovalReconciliationRechecksIsolationAndRemovesRemainingContainer(): void
    {
        $calls = [];
        $containment = new DockerWorkerContainment(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            return count($calls) === 2 ? json_encode(self::inspection(), JSON_THROW_ON_ERROR) : self::ID . "\n";
        });
        $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
        self::assertCount(3, $calls);
        self::assertSame('ls', $calls[0][1]);
        self::assertSame('inspect', $calls[1][1]);
        self::assertSame(self::ID, $calls[1][4]);
        self::assertSame(['container', 'rm', '--force', self::ID], $calls[2]);
    }

    #[DataProvider('invalidReconciliationListings')]
    public function testDurableRemovalReconciliationRejectsAmbiguousListingWithoutInspection(string $output): void
    {
        $calls = 0;
        $containment = new DockerWorkerContainment(static function (array $argv) use ($output, &$calls): string {
            ++$calls;
            self::assertSame('ls', $argv[1], 'Untrusted listing cannot progress to inspection or removal.');
            return $output;
        });
        try {
            $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
            self::fail('Ambiguous listing cannot reconcile removal.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidReconciliationListings(): iterable
    {
        yield 'other ID' => [str_repeat('f', 64)];
        yield 'truncated ID' => [substr(self::ID, 0, 12)];
        yield 'multiple matching IDs' => [self::ID . "\n" . self::ID];
        yield 'extra ID' => [self::ID . "\n" . str_repeat('f', 64)];
        yield 'diagnostic text' => ['Cannot connect to the Docker daemon'];
    }

    #[DataProvider('reconciliationFailureSteps')]
    public function testDurableRemovalReconciliationTransportFailureStopsAtFailedStep(int $step): void
    {
        $original = new RuntimeException('pinned daemon command failed');
        $calls = 0;
        $containment = new DockerWorkerContainment(static function () use ($step, $original, &$calls): string {
            if (++$calls === $step) {
                throw $original;
            }
            return $calls === 2 ? json_encode(self::inspection(), JSON_THROW_ON_ERROR) : self::ID;
        });
        try {
            $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
            self::fail('Transport failure cannot reconcile removal.');
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
            self::assertSame($step, $calls);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function reconciliationFailureSteps(): iterable
    {
        yield 'listing fails' => [1];
        yield 'inspection fails' => [2];
        yield 'removal fails' => [3];
    }

    #[DataProvider('unsafeInspections')]
    public function testDurableRemovalReconciliationNeverRemovesUnsafeRemainingContainer(string $field, mixed $value): void
    {
        $inspection = self::inspection();
        $inspection[$field] = $value;
        $calls = 0;
        $containment = new DockerWorkerContainment(static function (array $argv) use ($inspection, &$calls): string {
            ++$calls;
            self::assertNotSame('rm', $argv[1]);
            return $calls === 1 ? self::ID : json_encode($inspection, JSON_THROW_ON_ERROR);
        });
        try {
            $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
            self::fail('Unsafe remaining container cannot reconcile removal.');
        } catch (RuntimeException) {
            self::assertSame(2, $calls);
        }
    }

    #[DataProvider('unconfirmedRemovalOutputs')]
    public function testDurableRemovalReconciliationRequiresExactRemovalReceipt(string $output): void
    {
        $calls = 0;
        $containment = new DockerWorkerContainment(static function () use ($output, &$calls): string {
            return match (++$calls) {
                1 => self::ID,
                2 => json_encode(self::inspection(), JSON_THROW_ON_ERROR),
                default => $output,
            };
        });
        $this->expectException(RuntimeException::class);
        $containment->reconcileRemoval(self::ID, 'worker.baander.app', self::BOOT);
    }

    #[DataProvider('invalidReconciliationIdentities')]
    public function testInvalidReconciliationIdentityNeverReachesDocker(string $containerId, string $namespace, string $bootId): void
    {
        $containment = new DockerWorkerContainment(static function (): string {
            self::fail('Invalid identity must not reach Docker.');
        });
        $this->expectException(InvalidArgumentException::class);
        $containment->reconcileRemoval($containerId, $namespace, $bootId);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function invalidReconciliationIdentities(): iterable
    {
        yield 'unsafe ID' => ['--all', 'worker.baander.app', self::BOOT];
        yield 'unsafe namespace' => [self::ID, 'unsafe namespace', self::BOOT];
        yield 'unsafe boot ID' => [self::ID, 'worker.baander.app', 'unsafe'];
    }

    public function testPrestartIsolationVerificationRejectsWrongBoot(): void
    {
        $calls = 0;
        $containment = new DockerWorkerContainment(static function (array $argv) use (&$calls): string {
            ++$calls;
            self::assertSame('inspect', $argv[1]);
            return json_encode(self::inspection(), JSON_THROW_ON_ERROR);
        });
        try {
            $containment->verifyIsolation(self::ID, 'worker.baander.app', str_repeat('f', 32));
            self::fail('Wrong boot cannot pass prestart verification.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }
    }

    public function testInvalidPrestartIdentityNeverReachesDocker(): void
    {
        $containment = new DockerWorkerContainment(static function (): string {
            self::fail('Invalid identity must be rejected before inspection.');
        });
        $this->expectException(InvalidArgumentException::class);
        $containment->verifyIsolation(self::ID, 'unsafe namespace', self::BOOT);
    }

    #[DataProvider('unsafeInspections')]
    public function testUnsafeIdentityOrIsolationNeverAttemptsRemoval(string $field, mixed $value): void
    {
        $inspection = self::inspection();
        $inspection[$field] = $value;
        $calls = 0;
        $containment = new DockerWorkerContainment(static function (array $argv) use ($inspection, &$calls): string {
            ++$calls;
            self::assertSame('inspect', $argv[1], 'Unsafe inspection must never progress to removal.');
            return json_encode($inspection, JSON_THROW_ON_ERROR);
        });
        try {
            $containment->retire(self::ID, $this->lease());
            self::fail('Unsafe containment evidence must be rejected.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function unsafeInspections(): iterable
    {
        yield 'other immutable ID' => ['id', str_repeat('f', 64)];
        yield 'other deployment' => ['namespace', 'other.baander.app'];
        yield 'other boot' => ['bootId', str_repeat('f', 32)];
        yield 'individual child role' => ['role', 'worker'];
        yield 'missing deployment role' => ['role', null];
        yield 'privileged' => ['privileged', true];
        yield 'untyped privilege' => ['privileged', 'false'];
        yield 'host PID namespace' => ['pidMode', 'host'];
        yield 'joined PID namespace' => ['pidMode', 'container:' . self::ID];
        yield 'host cgroup namespace' => ['cgroupnsMode', 'host'];
        yield 'unspecified cgroup namespace' => ['cgroupnsMode', ''];
        yield 'bind mount' => ['mounts', [['Type' => 'bind', 'Source' => '/var/run/docker.sock']]];
        yield 'configured binds' => ['binds', ['/var/run/docker.sock:/var/run/docker.sock']];
        yield 'tmpfs mount' => ['tmpfs', ['/run' => 'rw']];
        yield 'device' => ['devices', [['PathOnHost' => '/dev/sda']]];
        yield 'GPU device request' => ['deviceRequests', [['Driver' => 'nvidia']]];
        yield 'added capability' => ['capAdd', ['SYS_ADMIN']];
        yield 'no drops' => ['capDrop', []];
        yield 'partial drops' => ['capDrop', ['NET_ADMIN']];
        yield 'no privilege restriction' => ['securityOpt', []];
        yield 'disabled restriction' => ['securityOpt', ['no-new-privileges:false']];
        yield 'unconfined seccomp' => ['securityOpt', ['no-new-privileges', 'seccomp=unconfined']];
        yield 'unexpected field' => ['state', 'stopped'];
    }

    #[DataProvider('invalidInspectionOutputs')]
    public function testMalformedOrMissingInspectionCannotCountAsContainment(string $output): void
    {
        $calls = 0;
        $containment = new DockerWorkerContainment(static function (array $argv) use ($output, &$calls): string {
            ++$calls;
            self::assertSame('inspect', $argv[1]);
            return $output;
        });
        try {
            $containment->retire(self::ID, $this->lease());
            self::fail('Malformed or absent evidence cannot prove containment.');
        } catch (RuntimeException) {
            self::assertSame(1, $calls);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidInspectionOutputs(): iterable
    {
        yield 'absent container' => [''];
        yield 'invalid JSON' => ['{'];
        yield 'null' => ['null'];
        yield 'multiple containers' => ['[]'];
        $missing = self::inspection();
        unset($missing['devices']);
        yield 'missing policy field' => [json_encode($missing, JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('unconfirmedRemovalOutputs')]
    public function testRemovalMustConfirmExactlyTheKnownImmutableId(string $output): void
    {
        $calls = 0;
        $containment = new DockerWorkerContainment(static function () use ($output, &$calls): string {
            return ++$calls === 1 ? json_encode(self::inspection(), JSON_THROW_ON_ERROR) : $output;
        });
        $this->expectException(RuntimeException::class);
        $containment->retire(self::ID, $this->lease());
    }

    /** @return iterable<string,array{string}> */
    public static function unconfirmedRemovalOutputs(): iterable
    {
        yield 'already absent' => [''];
        yield 'other container' => [str_repeat('f', 64)];
        yield 'short identifier' => [substr(self::ID, 0, 12)];
        yield 'additional output' => [self::ID . "\nother-container\n"];
    }

    #[DataProvider('failureSteps')]
    public function testExecutorFailurePropagatesOriginalException(int $step): void
    {
        $original = new RuntimeException('trusted command failed');
        $calls = 0;
        $containment = new DockerWorkerContainment(static function () use ($step, $original, &$calls): string {
            if (++$calls === $step) {
                throw $original;
            }
            return json_encode(self::inspection(), JSON_THROW_ON_ERROR);
        });
        try {
            $containment->retire(self::ID, $this->lease());
            self::fail('Command failure cannot supply containment proof.');
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
            self::assertSame($step, $calls);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function failureSteps(): iterable
    {
        yield 'inspect fails' => [1];
        yield 'removal fails' => [2];
    }

    public function testShortOrUserControlledIdNeverReachesExecutor(): void
    {
        $containment = new DockerWorkerContainment(static function (): string {
            self::fail('Invalid ID must not reach Docker.');
        });
        $this->expectException(InvalidArgumentException::class);
        $containment->retire('--all', $this->lease());
    }

    public function testNullEmptyResourcesAndExplicitPrivilegeFlagAreAccepted(): void
    {
        $inspection = self::inspection();
        foreach (['mounts', 'binds', 'tmpfs', 'devices', 'deviceRequests', 'capAdd'] as $field) {
            $inspection[$field] = null;
        }
        $inspection['securityOpt'] = ['no-new-privileges:true'];
        $calls = 0;
        $containment = new DockerWorkerContainment(static function () use ($inspection, &$calls): string {
            return ++$calls === 1 ? json_encode($inspection, JSON_THROW_ON_ERROR) : self::ID;
        });
        $containment->retire(self::ID, $this->lease());
        self::assertSame(2, $calls);
    }

    /** @return array<string,mixed> */
    private static function inspection(): array
    {
        return ['id' => self::ID, 'namespace' => 'worker.baander.app', 'bootId' => self::BOOT, 'role' => 'deployment',
            'privileged' => false, 'pidMode' => '', 'cgroupnsMode' => 'private', 'mounts' => [], 'binds' => [], 'tmpfs' => [],
            'devices' => [], 'deviceRequests' => [], 'capAdd' => [], 'capDrop' => ['ALL'], 'securityOpt' => ['no-new-privileges']];
    }

    private function lease(): DeploymentLease
    {
        return new DeploymentLease('worker.baander.app', self::BOOT, 7);
    }
}
