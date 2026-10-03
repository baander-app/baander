<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentContainerRecipeTest extends TestCase
{
    private const string BOOT = '0123456789abcdef0123456789abcdef';
    private const string IMAGE = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testRuntimeConfigurationParticipatesInIdentityWithoutEnteringArgv(): void
    {
        $variables = ['APP_ENV' => 'prod', 'APP_SECRET' => 'test-only-secret'];
        $recipe = $this->recipe(['runtimeEnvironment' => new DeploymentRuntimeEnvironment($variables)]);
        $reordered = $this->recipe(['runtimeEnvironment' => new DeploymentRuntimeEnvironment(array_reverse($variables, true))]);
        self::assertSame($recipe->fingerprint(), $reordered->fingerprint());
        self::assertNotSame($this->recipe()->fingerprint(), $recipe->fingerprint());
        self::assertNotSame($recipe->fingerprint(), $this->recipe(['runtimeEnvironment' => new DeploymentRuntimeEnvironment([
            ...$variables, 'APP_SECRET' => 'rotated-test-only-secret',
        ])])->fingerprint());
        $arguments = $recipe->createArguments('/tmp/baander-private/env');
        self::assertContains('--env-file', $arguments);
        self::assertContains('/tmp/baander-private/env', $arguments);
        self::assertStringNotContainsString('test-only-secret', implode(' ', $arguments));
    }

    public function testPopulatedRuntimeConfigurationCannotSilentlyUseImageDefaults(): void
    {
        $recipe = $this->recipe(['runtimeEnvironment' => new DeploymentRuntimeEnvironment(['APP_ENV' => 'prod'])]);
        $this->expectException(InvalidArgumentException::class);
        $recipe->createArguments();
    }

    public function testRelativeEnvironmentFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->recipe()->createArguments('relative.env');
    }

    public function testCreateArgvPreservesCommandBytesAndExplicitIsolation(): void
    {
        $recipe = $this->recipe(['command' => ['/usr/local/bin/php', '/app/bin/worker.php', '--name=literal $(touch /tmp/unsafe);`echo unsafe`']]);
        self::assertSame([
            'container', 'create', '--pull=never', '--name', RegisteredDeploymentStart::containerName('recipe.baander.app', self::BOOT),
            '--network', 'none', '--memory', '67108864', '--memory-swap', '67108864', '--cpus', '1', '--pids-limit', '32',
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cgroupns', 'private', '--restart', 'no',
            '--label', 'app.baander.worker.namespace=recipe.baander.app', '--label', 'app.baander.worker.boot-id=' . self::BOOT,
            '--label', 'app.baander.worker.role=deployment', '--env', 'BAANDER_WORKER_NAMESPACE=recipe.baander.app',
            '--env', 'BAANDER_WORKER_BOOT_ID=' . self::BOOT, '--entrypoint', '/usr/local/bin/php', self::IMAGE,
            '/app/bin/worker.php', '--name=literal $(touch /tmp/unsafe);`echo unsafe`',
        ], $recipe->createArguments());
    }

    public function testFingerprintIsDeterministicAndIncludesEveryConfigurationField(): void
    {
        $original = $this->recipe()->fingerprint();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $original);
        self::assertSame($original, $this->recipe()->fingerprint());
        foreach ([
            'namespace' => 'other.baander.app', 'bootId' => str_repeat('f', 32), 'daemonId' => 'other-daemon',
            'imageId' => 'sha256:' . str_repeat('f', 64), 'command' => ['/usr/local/bin/php', '/app/bin/other.php'],
            'network' => 'worker-network', 'memoryBytes' => 67108865, 'nanoCpus' => 1000000001, 'pidsLimit' => 33,
        ] as $field => $value) {
            self::assertNotSame($original, $this->recipe([$field => $value])->fingerprint(), $field . ' must affect durable create identity.');
        }
        self::assertNotSame($this->recipe(['command' => ['/x', 'ab', 'c']])->fingerprint(), $this->recipe(['command' => ['/x', 'a', 'bc']])->fingerprint(), 'Argument boundaries are part of the fingerprint.');
    }

    #[DataProvider('exactCpuValues')]
    public function testCpuFormattingUsesExactIntegerNanoseconds(int $nanoCpus, string $expected): void
    {
        $arguments = $this->recipe(['nanoCpus' => $nanoCpus])->createArguments();
        $position = array_search('--cpus', $arguments, true);
        self::assertNotFalse($position);
        self::assertSame($expected, $arguments[$position + 1]);
    }

    /** @return iterable<string,array{int,string}> */
    public static function exactCpuValues(): iterable
    {
        yield 'minimum' => [1_000_000, '0.001'];
        yield 'single nanosecond above minimum' => [1_000_001, '0.001000001'];
        yield 'fractional CPU' => [123_456_789, '0.123456789'];
        yield 'one CPU' => [1_000_000_000, '1'];
        yield 'fraction above integer' => [1_000_000_001, '1.000000001'];
        yield 'trailing zero fraction' => [1_500_000_000, '1.5'];
        yield 'maximum' => [64_000_000_000, '64'];
    }

    public function testInclusiveResourceAndCommandBoundariesAreAccepted(): void
    {
        $minimum = $this->recipe(['memoryBytes' => 16 * 1024 * 1024, 'nanoCpus' => 1_000_000, 'pidsLimit' => 8, 'command' => ['/x']]);
        self::assertSame(['/x'], $minimum->command);
        $maximum = $this->recipe(['memoryBytes' => 1024 * 1024 * 1024 * 1024, 'nanoCpus' => 64_000_000_000, 'pidsLimit' => 4096,
            'command' => ['/' . str_repeat('x', 4095), str_repeat('x', 4096), str_repeat('x', 4096), str_repeat('x', 4096)]]);
        self::assertSame(16384, array_sum(array_map(strlen(...), $maximum->command)));
        self::assertCount(64, $this->recipe(['command' => ['/x', ...array_fill(0, 63, 'x')]])->command);
        self::assertSame(str_repeat('n', 128), $this->recipe(['network' => str_repeat('n', 128)])->network);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidIdentityIsolationAndResourceBounds(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->recipe([$field => $value]);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'namespace whitespace' => ['namespace', 'recipe baander.app'];
        yield 'boot uppercase' => ['bootId', str_repeat('A', 32)];
        yield 'empty daemon' => ['daemonId', ''];
        yield 'oversized daemon' => ['daemonId', str_repeat('d', 129)];
        yield 'mutable image tag' => ['imageId', 'baander.app:latest'];
        yield 'short image hash' => ['imageId', 'sha256:abcd'];
        yield 'uppercase image hash' => ['imageId', 'sha256:' . str_repeat('A', 64)];
        yield 'host network' => ['network', 'host'];
        yield 'case host network' => ['network', 'HOST'];
        yield 'container network' => ['network', 'container'];
        yield 'joined network' => ['network', 'container:other'];
        yield 'network option' => ['network', '--host'];
        yield 'oversized network' => ['network', str_repeat('n', 129)];
        yield 'memory below minimum' => ['memoryBytes', 16 * 1024 * 1024 - 1];
        yield 'memory above maximum' => ['memoryBytes', 1024 * 1024 * 1024 * 1024 + 1];
        yield 'CPU below minimum' => ['nanoCpus', 999_999];
        yield 'CPU above maximum' => ['nanoCpus', 64_000_000_001];
        yield 'PID below minimum' => ['pidsLimit', 7];
        yield 'PID above maximum' => ['pidsLimit', 4097];
        yield 'no command' => ['command', []];
        yield 'relative executable' => ['command', ['php']];
        yield 'empty argument' => ['command', ['/x', '']];
        yield 'NUL argument' => ['command', ['/x', "bad\0argument"]];
        yield 'nonstring argument' => ['command', ['/x', 1]];
        yield 'unordered command' => ['command', [1 => '/x']];
        yield 'argument over limit' => ['command', ['/x', str_repeat('x', 4097)]];
        yield 'too many arguments' => ['command', ['/x', ...array_fill(0, 64, 'x')]];
        yield 'total over limit' => ['command', ['/' . str_repeat('x', 4095), str_repeat('x', 4096), str_repeat('x', 4096), str_repeat('x', 4096), 'x']];
    }

    /** @param array<string,mixed> $overrides */
    private function recipe(array $overrides = []): DeploymentContainerRecipe
    {
        return new DeploymentContainerRecipe(...array_replace([
            'namespace' => 'recipe.baander.app', 'bootId' => self::BOOT, 'daemonId' => 'docker-daemon', 'imageId' => self::IMAGE,
            'command' => ['/usr/local/bin/php', '/app/bin/worker.php'], 'network' => 'none', 'memoryBytes' => 64 * 1024 * 1024,
            'nanoCpus' => 1_000_000_000, 'pidsLimit' => 32,
        ], $overrides));
    }
}
