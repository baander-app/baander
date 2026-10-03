<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DeploymentOperatorCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeploymentOperatorCredentialsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-operator-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testLoadsOnlyExplicitConfigurationAndRedactsSecrets(): void
    {
        $previous = getenv('APP_SECRET');
        putenv('APP_SECRET=ambient-secret');
        try {
            $credentials = DeploymentOperatorCredentials::fromFile($this->write(self::document()));
            self::assertSame([
                'driver' => 'pdo_pgsql', 'host' => 'db.baander.app', 'port' => 5432,
                'user' => 'operator', 'password' => 'controller-private', 'dbname' => 'baander',
                'sslmode' => 'require',
            ], $credentials->connectionParameters());
            self::assertSame('runtime-private', $credentials->runtimeEnvironment->variables['APP_SECRET']);
            self::assertSame('prod', $credentials->runtimeEnvironment->variables['APP_ENV']);
            self::assertStringNotContainsString('private', json_encode($credentials, JSON_THROW_ON_ERROR));
            ob_start();
            var_dump($credentials);
            $debug = ob_get_clean();
            self::assertStringNotContainsString('private', $debug);
            self::assertSame('[redacted]', $credentials->__debugInfo()['controllerDatabaseUrl']);
        } finally {
            putenv($previous === false ? 'APP_SECRET' : 'APP_SECRET=' . $previous);
        }
    }

    #[DataProvider('privateModes')]
    public function testAcceptsOwnerOnlyModes(int $mode): void
    {
        $path = $this->write(self::document());
        chmod($path, $mode);
        self::assertInstanceOf(DeploymentOperatorCredentials::class, DeploymentOperatorCredentials::fromFile($path));
    }

    /** @return iterable<string,array{int}> */
    public static function privateModes(): iterable
    {
        yield 'read write' => [0600];
        yield 'read only' => [0400];
    }

    #[DataProvider('insecureModes')]
    public function testRejectsEveryGroupOrWorldPermission(int $mode): void
    {
        $path = $this->write(self::document());
        chmod($path, $mode);
        $this->assertRejected($path);
    }

    /** @return iterable<string,array{int}> */
    public static function insecureModes(): iterable
    {
        foreach ([0640, 0620, 0610, 0604, 0602, 0601, 0644, 0666] as $mode) {
            yield decoct($mode) => [$mode];
        }
    }

    public function testRejectsSymlinkAndSymlinkParent(): void
    {
        $path = $this->write(self::document());
        self::assertTrue(symlink($path, $this->directory . '/link'));
        $this->assertRejected($this->directory . '/link');
        self::assertTrue(symlink($this->directory, $this->directory . '/parent'));
        $this->assertRejected($this->directory . '/parent/credentials');
    }

    public function testRejectsMissingRelativeDirectoryAndFifoWithoutOpeningThem(): void
    {
        $this->assertRejected($this->directory . '/missing');
        $this->assertRejected('credentials');
        $this->assertRejected($this->directory);
        self::assertTrue(posix_mkfifo($this->directory . '/fifo', 0600));
        $this->assertRejected($this->directory . '/fifo');
    }

    public function testRejectsOversizedAndEmptyFiles(): void
    {
        $this->assertRejected($this->write(str_repeat('x', 8193)));
        $this->assertRejected($this->write(''));
    }

    public function testRejectsForeignOwnerWhenOwnershipCanBeChanged(): void
    {
        $path = $this->write(self::document());
        if (posix_geteuid() === 0) {
            self::assertTrue(chown($path, 65534));
            $this->assertRejected($path);
        } else {
            // Confirm the normal accepted contract without requiring elevated privileges.
            self::assertSame(posix_geteuid(), fileowner($path));
            self::assertInstanceOf(DeploymentOperatorCredentials::class, DeploymentOperatorCredentials::fromFile($path));
        }
    }

    #[DataProvider('invalidDocuments')]
    public function testRejectsMalformedConfigurationWithoutSecretExceptionChain(string $document): void
    {
        $this->assertRejected($this->write($document));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDocuments(): iterable
    {
        yield 'malformed JSON' => ['{"controllerDatabaseUrl":"controller-private"'];
        yield 'JSON array' => ['[]'];
        yield 'null' => ['null'];
        $base = json_decode(self::document(), true, 6, JSON_THROW_ON_ERROR);
        foreach (['controllerDatabaseUrl', 'runtimeEnvironment'] as $key) {
            $document = $base;
            unset($document[$key]);
            yield 'missing ' . $key => [json_encode($document, JSON_THROW_ON_ERROR)];
            foreach ([null, 1, [], true] as $index => $value) {
                $document = $base;
                $document[$key] = $value;
                yield 'wrong type ' . $key . $index => [json_encode($document, JSON_THROW_ON_ERROR)];
            }
        }
        $document = $base;
        $document['unknown'] = 'controller-private';
        yield 'unknown top-level key' => [json_encode($document, JSON_THROW_ON_ERROR)];
        foreach (['APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'REDIS_URL', 'MESSENGER_TRANSPORT_DSN', 'APP_SECRET'] as $key) {
            $document = $base;
            unset($document['runtimeEnvironment'][$key]);
            yield 'missing environment ' . $key => [json_encode($document, JSON_THROW_ON_ERROR)];
            $document['runtimeEnvironment'][$key] = '';
            yield 'empty environment ' . $key => [json_encode($document, JSON_THROW_ON_ERROR)];
        }
        foreach (['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'LD_PRELOAD' => 'controller-private', 'APP_SECRET' => "controller-private\n"] as $key => $value) {
            $document = $base;
            $document['runtimeEnvironment'][$key] = $value;
            yield 'invalid environment ' . $key => [json_encode($document, JSON_THROW_ON_ERROR)];
        }
        foreach ([
            'mysql://operator:controller-private@db.baander.app/baander',
            'postgresql://controller-private',
            'postgresql://operator:controller-private@db.baander.app/',
            'postgresql://operator:controller-private@db.baander.app:99999/baander',
            'postgresql://operator:controller-private@db.baander.app/baander?driver=pdo_mysql',
            'postgresql://operator:controller-private@db.baander.app/baander?driverClass=Unexpected',
            'postgresql://operator:controller-private@db.baander.app/baander?wrapperClass=Unexpected',
            'postgresql://operator:controller-private@db.baander.app/baander?host[]=db.baander.app',
            'postgresql://operator:controller-private@db.baander.app/baander%3bhost=other.baander.app',
        ] as $index => $url) {
            $document = $base;
            $document['controllerDatabaseUrl'] = $url;
            yield 'invalid DSN ' . $index => [json_encode($document, JSON_THROW_ON_ERROR)];
        }
        yield 'depth bound' => ['{"controllerDatabaseUrl":"controller-private","runtimeEnvironment":{"APP_SECRET":[[[[[["controller-private"]]]]]]}}'];
    }

    private function write(string $contents): string
    {
        $path = $this->directory . '/credentials';
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0600));

        return $path;
    }

    private function assertRejected(string $path): void
    {
        try {
            DeploymentOperatorCredentials::fromFile($path);
            self::fail('Invalid credentials must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('controller-private', (string) $exception);
            self::assertStringNotContainsString('runtime-private', (string) $exception);
            foreach ($exception->getTrace() as $frame) {
                self::assertNotContains('parseDatabaseUrl', [$frame['function']]);
                self::assertNotContains('json_decode', [$frame['function']]);
            }
        }
    }

    private static function document(): string
    {
        return json_encode([
            'controllerDatabaseUrl' => 'postgresql://operator:controller-private@db.baander.app:5432/baander?sslmode=require',
            'runtimeEnvironment' => [
                'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => 'runtime-private',
                'DATABASE_URL' => 'postgresql://runtime:runtime-private@db.baander.app/baander',
                'REDIS_URL' => 'redis://redis.baander.app/0', 'MESSENGER_TRANSPORT_DSN' => 'redis://redis.baander.app/messages',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
