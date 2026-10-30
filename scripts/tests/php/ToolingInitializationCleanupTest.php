<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolingInitializationCleanupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-init-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/certificates', 0700);
        file_put_contents($this->directory . '/certificates/ca.pem', 'private connection material');
        mkdir($this->directory . '/certificates/cert.pem', 0700);
    }

    protected function tearDown(): void
    {
        Collector::remove($this->directory);
    }

    /** @return iterable<string,array{string}> */
    public static function entrypoints(): iterable
    {
        yield 'inventory' => ['inventory'];
        yield 'static check' => ['static'];
    }

    #[DataProvider('entrypoints')]
    public function testPartialTransportPreparationIsRemovedWhenPreflightFails(string $workflow): void
    {
        $environment = array_replace(getenv(), [
            'TMPDIR' => $this->directory,
            'BAANDER_DOCKER_EXECUTABLE' => '/usr/bin/true',
            'DOCKER_HOST' => 'unix:///baander.app/docker.sock',
            'DOCKER_TLS' => '1',
            'DOCKER_CERT_PATH' => $this->directory . '/certificates',
        ]);
        unset($environment['DOCKER_CONTEXT'], $environment['BAANDER_TEST_DOCKER_CONTEXT'], $environment['DOCKER_TLS_VERIFY']);
        $scripts = dirname(__DIR__, 2);
        $arguments = $workflow === 'inventory'
            ? [$scripts . '/packaged_inventory.php', 'collect', '--image', 'baander.app/app:fixture', '--target', 'production', '--revision', 'abc', '--output', $this->directory . '/bundle', '--session', $this->directory . '/session']
            : [$scripts . '/check-backend-container.php', '--image', 'baander.app/app:fixture', '--', 'php', '-v'];
        $executor = new EngineExecutor(ExecutionContext::bounded(20, $environment));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, ...$arguments], environment: $environment), new CompletionPolicy(null));
        } finally {
            $executor->close();
        }
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Docker TLS connection material is unreadable: cert.pem', $result->stderr);
        self::assertSame('', $result->stdout);
        self::assertSame([], glob($this->directory . '/baander-check-*'));
        self::assertDirectoryDoesNotExist($this->directory . '/session');
        self::assertSame('private connection material', file_get_contents($this->directory . '/certificates/ca.pem'));
    }

    public function testExistingSessionDataIsPreservedWhenCollectionCannotOwnIt(): void
    {
        $session = $this->directory . '/session';
        mkdir($session, 0700);
        file_put_contents($session . '/saved-recovery-record', 'preserve this session');
        $environment = array_replace(getenv(), ['TMPDIR' => $this->directory]);
        $executor = new EngineExecutor(ExecutionContext::bounded(20, $environment));
        try {
            $result = $executor->run(new ProcessSpec([
                PHP_BINARY, dirname(__DIR__, 2) . '/packaged_inventory.php', 'collect',
                '--image', 'baander.app/app:fixture', '--target', 'production', '--revision', 'abc',
                '--output', $this->directory . '/bundle', '--session', $session,
            ], environment: $environment), new CompletionPolicy(null));
        } finally {
            $executor->close();
        }
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Inventory session is not empty', $result->stderr);
        self::assertSame('preserve this session', file_get_contents($session . '/saved-recovery-record'));
        self::assertDirectoryDoesNotExist($session . '/transport');
    }
}
