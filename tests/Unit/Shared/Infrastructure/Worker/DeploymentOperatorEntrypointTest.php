<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use PHPUnit\Framework\TestCase;

final class DeploymentOperatorEntrypointTest extends TestCase
{
    private const string SECRET = 'operator-test-private-secret';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-operator-entrypoint-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testHelpRunsWithoutConfiguration(): void
    {
        [$exit, $stdout, $stderr] = $this->invoke(['--help']);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertSame(
            "Usage: php bin/worker-deployment.php <create|reconcile-create|start|status|recover> /absolute/manifest.json /absolute/credentials.json\n"
            . "Use an external deadline. Unconfirmed create/start must be reconciled, never retried automatically.\n",
            $stdout,
        );
    }

    public function testInvalidActionsAndArgumentCountsAreRejected(): void
    {
        foreach ([[], ['create'], ['--help', 'extra'], ['arbitrary', self::SECRET, self::SECRET],
            ['status', '/missing-manifest', '/missing-credentials', self::SECRET]] as $arguments) {
            $this->assertInvalid($arguments, null);
        }
    }

    public function testRelativeAndNonregularManifestPathsAreRejected(): void
    {
        $this->assertInvalid(['create', 'relative-' . self::SECRET, $this->directory . '/credentials'], null);
        $this->assertInvalid(['create', $this->directory, $this->directory . '/credentials'], null);
        $this->assertInvalid(['create', $this->directory . '/missing-manifest', $this->directory . '/credentials'], null);
    }

    public function testOversizedMalformedAndUnknownManifestFieldsAreRejected(): void
    {
        foreach ([
            str_repeat(' ', 8193),
            '{"secret":"' . self::SECRET . '",',
            json_encode([...self::manifest(), 'arbitraryCommand' => ['/bin/sh', '-c', self::SECRET]], JSON_THROW_ON_ERROR),
        ] as $document) {
            $manifest = $this->write('manifest.json', $document);
            // These documents must fail with no credential document available.
            $this->assertInvalid(['create', $manifest, $this->directory . '/missing-' . self::SECRET], 'create');
        }
    }

    public function testCredentialLoadFailuresWithValidManifestAreRedacted(): void
    {
        $manifest = $this->write('manifest.json', json_encode(self::manifest(), JSON_THROW_ON_ERROR));
        $this->assertInvalid(['status', $manifest, $this->directory . '/missing-' . self::SECRET], 'status');
        $this->assertInvalid(['recover', $manifest, 'relative-' . self::SECRET], 'recover');
        $credentials = $this->write('credentials.json', '{"controllerDatabaseUrl":"' . self::SECRET . '",');
        $this->assertInvalid(['start', $manifest, $credentials], 'start');
        chmod($credentials, 0644);
        $this->assertInvalid(['reconcile-create', $manifest, $credentials], 'reconcile-create');
    }

    /** @param list<string> $arguments */
    private function assertInvalid(array $arguments, ?string $action): void
    {
        [$exit, $stdout, $stderr] = $this->invoke($arguments);
        self::assertSame(2, $exit);
        self::assertSame('', $stderr);
        self::assertSame(json_encode(['success' => false, 'action' => $action, 'error' => 'invalid_configuration'], JSON_THROW_ON_ERROR) . "\n", $stdout);
        self::assertLessThan(256, strlen($stdout));
        self::assertStringNotContainsString(self::SECRET, $stdout);
        self::assertStringNotContainsString($this->directory, $stdout);
        self::assertStringNotContainsString('Stack trace', $stdout);
    }

    /**
     * @param list<string> $arguments
     * @return array{?int,string,string}
     */
    private function invoke(array $arguments): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $project = dirname(__DIR__, 5);
        $child = null;
        try {
            $child = WorkerChildProcess::start([PHP_BINARY, $project . '/bin/worker-deployment.php', ...$arguments], $project, $stdout, $stderr, []);
            $deadline = hrtime(true) / 1e9 + 5;
            while ($child->poll(hrtime(true) / 1e9)) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    $child->requestStop(hrtime(true) / 1e9, 0);
                    self::fail('Operator configuration validation did not finish within five seconds.');
                }
                usleep(1000);
            }
            $output = file_get_contents(stream_get_meta_data($stdout)['uri'], false, null, 0, 8193);
            $diagnostics = file_get_contents(stream_get_meta_data($stderr)['uri'], false, null, 0, 8193);
            self::assertIsString($output);
            self::assertIsString($diagnostics);
            self::assertLessThanOrEqual(8192, strlen($output));
            self::assertLessThanOrEqual(8192, strlen($diagnostics));
            return [$child->exitCode(), $output, $diagnostics];
        } finally {
            unset($child);
            fclose($stdout);
            fclose($stderr);
        }
    }

    private function write(string $name, string $document): string
    {
        $path = $this->directory . '/' . $name;
        self::assertSame(strlen($document), file_put_contents($path, $document));
        self::assertTrue(chmod($path, 0600));
        return $path;
    }

    /** @return array<string,int|string> */
    private static function manifest(): array
    {
        return [
            'version' => 1, 'namespace' => 'operator.baander.app', 'bootId' => str_repeat('a', 32),
            'daemonId' => 'docker-daemon', 'imageId' => 'sha256:' . str_repeat('b', 64), 'network' => 'none',
            'dockerBinary' => '/bin/true', 'dockerEndpoint' => 'unix:///var/run/docker.sock',
            'memoryMiB' => 1280, 'managementMiB' => 128, 'consumerMiB' => 320, 'relayMiB' => 320,
            'schedulerMiB' => 320, 'scheduledConsoleMiB' => 192, 'nanoCpus' => 1000000000, 'pidsLimit' => 64,
        ];
    }
}
