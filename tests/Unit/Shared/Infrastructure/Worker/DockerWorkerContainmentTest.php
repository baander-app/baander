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
