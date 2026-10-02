<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DockerWorkerCommand;
use PHPUnit\Framework\TestCase;

final class DockerWorkerCommandTest extends TestCase
{
    private string $executable;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-docker-command-');
        self::assertNotFalse($path);
        $this->executable = $path;
        chmod($path, 0700);
    }

    protected function tearDown(): void
    {
        unlink($this->executable);
    }

    public function testPinsEndpointAndPassesArgumentsWithoutShellOrAmbientContext(): void
    {
        $this->script('echo json_encode([$argv, getenv("DOCKER_CONTEXT"), getenv("DOCKER_CONFIG")]);');
        putenv('DOCKER_CONTEXT=untrusted-baander.app');
        try {
            $output = (new DockerWorkerCommand($this->executable, 'unix:///tmp/baander.app.sock'))->execute(['container', 'inspect', '$(touch /tmp/baander-no-shell)']);
        } finally {
            putenv('DOCKER_CONTEXT');
        }
        $decoded = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([$this->executable, '--host', 'unix:///tmp/baander.app.sock', 'container', 'inspect', '$(touch /tmp/baander-no-shell)'], $decoded[0]);
        self::assertFalse($decoded[1]);
        self::assertStringStartsWith(sys_get_temp_dir() . '/baander-docker-config-', $decoded[2]);
        self::assertDirectoryDoesNotExist($decoded[2]);
    }

    public function testTimeoutDoesNotWaitForTheCommandToFinish(): void
    {
        $this->script('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); usleep(5000000);');
        $started = microtime(true);
        try {
            (new DockerWorkerCommand($this->executable, timeoutSeconds: 0.05))->execute([]);
            self::fail('A stalled CLI must not confirm retirement.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
            self::assertLessThan(2, microtime(true) - $started);
        }
    }

    public function testNonzeroExitDoesNotExposeDaemonDiagnostics(): void
    {
        $this->script('fwrite(STDERR, "private-diagnostic"); exit(1);');
        $this->expectExceptionMessage('Docker containment command failed; no retirement is confirmed.');
        (new DockerWorkerCommand($this->executable))->execute([]);
    }

    public function testOversizedSuccessfulOutputIsRejected(): void
    {
        $this->script('echo str_repeat("x", 16385);');
        $this->expectExceptionMessage('output limit');
        (new DockerWorkerCommand($this->executable))->execute([]);
    }

    public function testOversizedStderrIsRejectedEvenOnSuccess(): void
    {
        $this->script('fwrite(STDERR, str_repeat("x", 16385));');
        $this->expectExceptionMessage('output limit');
        (new DockerWorkerCommand($this->executable))->execute([]);
    }

    public function testRemoteDaemonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DockerWorkerCommand($this->executable, 'tcp://registry.baander.app:2375');
    }

    private function script(string $body): void
    {
        file_put_contents($this->executable, '#!' . PHP_BINARY . "\n<?php " . $body);
    }
}
