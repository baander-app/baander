<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class RegistryImageCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-registry-image-cli-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/scripts', 0700, true);
        mkdir($this->directory . '/relay', 0700);
        $root = dirname(__DIR__, 3);
        copy($root . '/scripts/verify-registry-image.php', $this->directory . '/scripts/verify-registry-image.php');
        symlink($root . '/scripts/bootstrap.php', $this->directory . '/scripts/bootstrap.php');
        symlink($root . '/relay/tools', $this->directory . '/relay/tools');
        symlink($root . '/relay/vendor', $this->directory . '/relay/vendor');
        copy(__DIR__ . '/Fixtures/registry-image-docker.php', $this->directory . '/docker');
        chmod($this->directory . '/docker', 0700);
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $remove($path . '/' . $name);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->directory);
    }

    private function invoke(ProcessSpec $command): ProcessResult
    {
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            return $executor->run($command, new CompletionPolicy([0, 1]));
        } finally {
            $executor->close();
        }
    }

    public function testHelpNeedsNoDockerOrEvidenceDirectory(): void
    {
        $result = $this->invoke(new ProcessSpec(
            [PHP_BINARY, $this->directory . '/scripts/verify-registry-image.php', '--help'],
            environment: array_replace(getenv(), ['DOCKER_CONTEXT' => 'unavailable', 'PATH' => '']),
            timeout: 10,
            processGroup: true,
            replaceEnvironment: true,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('--image IMAGE --kind api|rqlite|backup --output DIRECTORY', $result->stdout);
        self::assertDirectoryDoesNotExist($this->directory . '/var');
    }

    public function testFailedCreateRetainsOwnershipAndRetriesCleanupOnTheFrozenDaemon(): void
    {
        $result = $this->invoke(new ProcessSpec(
            [PHP_BINARY, $this->directory . '/scripts/verify-registry-image.php', '--image', 'registry.baander.app/api:fixture', '--kind', 'api', '--output', $this->directory . '/inventory'],
            environment: array_replace(getenv(), [
                'CI' => '1',
                'PATH' => $this->directory . ':' . getenv('PATH'),
                'DOCKER_CONTEXT' => 'initial',
                'DOCKER_HOST' => 'unix:///tmp/ignored.sock',
                'BAANDER_REGISTRY_IMAGE_FIXTURE' => $this->directory,
                'BAANDER_CLEANUP_TIMEOUT' => '5',
            ]),
            timeout: 20,
            processGroup: true,
            replaceEnvironment: true,
        ));
        self::assertSame(1, $result->exitCode, $result->stderr);
        self::assertStringContainsString('fixture create failed', $result->stderr);
        self::assertStringContainsString('Retry receipt:', $result->stderr);
        $receipts = glob($this->directory . '/var/test-runs/registry-image/*/relay-resources.json') ?: [];
        self::assertCount(1, $receipts);
        $receipt = json_decode(file_get_contents($receipts[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unix:///tmp/initial.sock', $receipt['target']['endpoint']);
        self::assertCount(1, $receipt['resources']);
        self::assertSame(0, fileperms(dirname($receipts[0])) & 0077);
        self::assertSame(0, fileperms($receipts[0]) & 0077);
        $commands = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES));
        $hostCommands = array_values(array_filter($commands, static fn(array $argv): bool => ($argv[0] ?? '') === '--host'));
        self::assertNotEmpty($hostCommands);
        foreach ($hostCommands as $argv) {
            self::assertSame('unix:///tmp/initial.sock', $argv[1]);
        }
        $inspections = array_filter($hostCommands, static fn(array $argv): bool => ($argv[2] ?? '') === 'container' && ($argv[3] ?? '') === 'inspect');
        self::assertCount(3, $inspections, 'Creation observation, relay cleanup and independent parent cleanup must each attempt ownership resolution.');
        self::assertSame('changed', file_get_contents($this->directory . '/context'));
        self::assertDirectoryDoesNotExist($this->directory . '/inventory');
    }
}
