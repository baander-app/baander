<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeploymentRuntimeEnvironmentTest extends TestCase
{
    public function testFilePreservesLiteralValuesSortingPermissionsAndReturnValue(): void
    {
        $secret = 'Bånder=# "quoted" $(touch /tmp/unsafe);`echo unsafe`';
        $environment = new DeploymentRuntimeEnvironment(['REDIS_PASSWORD' => $secret, 'APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
        self::assertSame(['APP_DEBUG' => '0', 'APP_ENV' => 'prod', 'REDIS_PASSWORD' => $secret], $environment->variables);
        $savedPath = null;
        self::assertSame(123, $environment->withFile(static function (string $path) use ($secret, &$savedPath): int {
            $savedPath = $path;
            self::assertStringStartsWith('/', $path);
            self::assertSame(0700, fileperms(dirname($path)) & 0777);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame("APP_DEBUG=0\nAPP_ENV=prod\nREDIS_PASSWORD=" . $secret . "\n", file_get_contents($path));

            return 123;
        }));
        self::assertIsString($savedPath);
        self::assertFileDoesNotExist($savedPath);
        self::assertDirectoryDoesNotExist(dirname($savedPath));
    }

    public function testCleanupPreservesCallbackException(): void
    {
        $savedPath = null;
        $failure = new RuntimeException('operation failed');
        try {
            new DeploymentRuntimeEnvironment(['APP_SECRET' => 'private'])->withFile(static function (string $path) use (&$savedPath, $failure): never {
                $savedPath = $path;
                throw $failure;
            });
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertIsString($savedPath);
        self::assertFileDoesNotExist($savedPath);
        self::assertDirectoryDoesNotExist(dirname($savedPath));
    }

    public function testEmptyMapCreatesEmptyFileWithoutReadingAmbientEnvironment(): void
    {
        $previous = getenv('APP_SECRET');
        putenv('APP_SECRET=ambient-secret');
        try {
            $environment = new DeploymentRuntimeEnvironment();
            self::assertSame([], $environment->variables);
            $environment->withFile(static function (string $path): void {
                self::assertSame('', file_get_contents($path));
            });
        } finally {
            putenv($previous === false ? 'APP_SECRET' : 'APP_SECRET=' . $previous);
        }
    }

    public function testCleanupFailureCannotSilentlyReportSuccess(): void
    {
        $savedPath = null;
        try {
            try {
                new DeploymentRuntimeEnvironment(['APP_SECRET' => 'private-input'])->withFile(static function (string $path) use (&$savedPath): void {
                    $savedPath = $path;
                    self::assertTrue(unlink($path));
                    self::assertTrue(mkdir($path, 0700));
                    self::assertSame(1, file_put_contents($path . '/retained', 'x'));
                });
                self::fail('Failed artifact removal must reject the operation.');
            } catch (RuntimeException $exception) {
                self::assertSame('Cannot remove private runtime environment artifacts.', $exception->getMessage());
                self::assertStringNotContainsString('private-input', $exception->getMessage());
            }
        } finally {
            if (is_string($savedPath)) {
                unlink($savedPath . '/retained');
                rmdir($savedPath);
                rmdir(dirname($savedPath));
            }
        }
    }

    public function testAdmitsConfiguredApplicationInputsAndEmptyValues(): void
    {
        $variables = ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'DATABASE_URL' => 'postgresql://db.baander.app/baander',
            'REDIS_URL' => 'redis://redis.baander.app/0', 'REDIS_PASSWORD' => '', 'APP_SECRET' => 'secret',
            'MESSENGER_TRANSPORT_DSN' => 'redis://redis.baander.app/messages', 'MAILER_DSN' => 'smtp://mail.baander.app'];
        ksort($variables);
        self::assertSame($variables, new DeploymentRuntimeEnvironment($variables)->variables);
    }

    public function testByteLimitsIncludeKeysEqualsAndNewlines(): void
    {
        // Two encoded lines total 4096 bytes (12 + 14 bytes of key/separators).
        $environment = new DeploymentRuntimeEnvironment(['APP_SECRET' => str_repeat('x', 2048), 'DATABASE_URL' => str_repeat('x', 2022)]);
        $environment->withFile(static function (string $path): void {
            self::assertSame(4096, filesize($path));
        });
        $this->expectException(InvalidArgumentException::class);
        new DeploymentRuntimeEnvironment(['APP_SECRET' => str_repeat('x', 2048), 'DATABASE_URL' => str_repeat('x', 2023)]);
    }

    public function testDebugAndJsonDoNotRevealValues(): void
    {
        $environment = new DeploymentRuntimeEnvironment(['APP_SECRET' => 'unique-private-secret']);
        self::assertSame(['variables' => ['APP_SECRET' => '[redacted]']], $environment->__debugInfo());
        self::assertStringNotContainsString('unique-private-secret', json_encode($environment, JSON_THROW_ON_ERROR));
        ob_start();
        var_dump($environment);
        $dump = ob_get_clean();
        self::assertStringNotContainsString('unique-private-secret', $dump);
    }

    /** @param array<array-key,mixed> $variables */
    #[DataProvider('invalidVariables')]
    public function testRejectsInvalidInputWithoutRevealingValues(array $variables): void
    {
        try {
            new DeploymentRuntimeEnvironment($variables);
            self::fail('Invalid environment must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('private-input', $exception->getMessage());
        }
    }

    /** @return iterable<string,array{array<array-key,mixed>}> */
    public static function invalidVariables(): iterable
    {
        foreach (['app_secret', 'APP-SECRET', 'APP_SECRET\n', '0APP', '', 'UNKNOWN_SETTING',
            'BAANDER_WORKER_NAMESPACE', 'BAANDER_WORKER_BOOT_ID', 'BAANDER_WORKER_OTHER',
            'PATH', 'LD_PRELOAD', 'LD_LIBRARY_PATH', 'PHP_INI_SCAN_DIR', 'PHPRC', 'HOME', 'SHELL',
        ] as $key) {
            yield 'key ' . $key => [[$key => 'private-input']];
        }
        yield 'integer key' => [[0 => 'private-input']];
        yield 'nonstring value' => [['APP_SECRET' => 1]];
        foreach (["private-input\0", "private-input\r", "private-input\n", "private-input\t", "private-input\x7f", "private-input\xff", "private-input\u{2028}", "private-input\u{200b}"] as $index => $value) {
            yield 'invalid bytes ' . $index => [['APP_SECRET' => $value]];
        }
        yield 'per-value oversized' => [['APP_SECRET' => 'private-input' . str_repeat('x', 2048)]];
        yield 'nonproduction' => [['APP_ENV' => 'private-input']];
        yield 'debug enabled' => [['APP_DEBUG' => '1']];
        yield 'too many entries' => [array_fill_keys(array_map(static fn (int $index): string => 'KEY_' . $index, range(1, 33)), 'private-input')];
    }
}
