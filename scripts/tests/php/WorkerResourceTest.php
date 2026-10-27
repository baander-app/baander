<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\{Environment, Runner};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Fixtures\Worker\Resources;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerResourceTest extends TestCase
{
    public function testFailedInventoryStillRemovesOwnedContainerAndRetainsCleanupReceipt(): void
    {
        $directory = sys_get_temp_dir() . '/baander-worker-resource-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy(__DIR__ . '/Fixtures/worker-resource-docker.php', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0755);
        $previousPath = getenv('PATH');
        $previousArtifacts = getenv('BAANDER_TEST_ARTIFACTS');
        putenv('PATH=' . $directory . '/bin:' . getenv('PATH'));
        putenv('BAANDER_TEST_ARTIFACTS=' . $directory . '/artifacts');
        $runtime = new Runtime(
            dirname(__DIR__, 3),
            1,
            array_replace(getenv(), ['WORKER_RESOURCE_COMMANDS' => $directory . '/commands.jsonl']),
        );
        $resources = new Resources($runtime, 'resource-test');
        $resources->containers[] = $resources->id . '-owned';
        try {
            $runtime->pause(1.1);
        } catch (RuntimeException) {
            // Cleanup has a fresh deadline even after the fixture expires.
        }
        try {
            try {
                $resources->close();
                self::fail('Failed inventory reported successful cleanup');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('cleanup failed', $error->getMessage());
            }
            $commands = file($directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
            self::assertTrue(array_any(
                $commands,
                static fn(array $command): bool => in_array('rm', $command, true) && in_array($resources->id . '-owned', $command, true),
            ));
            $receipt = Runtime::json($directory . '/artifacts/cleanup.json');
            self::assertFalse($receipt['cleanupVerified']);
            self::assertSame([], $receipt['remaining']['containers']);
            self::assertStringContainsString('inventory fixture failure', implode('; ', $receipt['errors']));
        } finally {
            $runtime->close();
            putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
            putenv($previousArtifacts === false ? 'BAANDER_TEST_ARTIFACTS' : 'BAANDER_TEST_ARTIFACTS=' . $previousArtifacts);
            new Runner()->run(['rm', '-rf', '--', $directory]);
        }
    }
}
