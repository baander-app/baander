<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\ImageProfile;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use PHPUnit\Framework\TestCase;

final class ImageProfileTest extends TestCase
{
    /** @return array<string,mixed> */
    private function document(): array
    {
        return [
            'version' => 1,
            'deployment' => ['identifier' => 'household', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2, 'videoEncoderSlots' => 0, 'audioEncoderSlots' => 0],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'phpProcessMemoryMiB' => 320, 'nativeEnvelopeMiB' => 3136, 'nativeCommandLimit' => 5],
            'budgets' => ['controlMemoryMiB' => 2304, 'executionMemoryMiB' => 4480, 'controlPidsLimit' => 129, 'executionPidsLimit' => 256, 'scheduledConsoleMiB' => 320],
        ];
    }

    public function testImageValuesSurvivePersistenceWithoutRecalculatingBudgets(): void
    {
        $document = $this->document();
        $document['budgets']['executionMemoryMiB'] = 3000;
        $profile = ImageProfile::fromArray($document);
        self::assertSame(3000, $profile->executionMemoryMiB);
        self::assertSame(2304, $profile->controlMemoryMiB);
        self::assertSame(2, $profile->webWorkers);
        self::assertSame(4, $profile->doctrinePoolSlots);
        self::assertSame(256, $profile->phpHeapMiB);
        self::assertSame($profile->fingerprint(), ImageProfile::fromArray($profile->toArray())->fingerprint());
    }

    public function testFingerprintIncludesNativeLimitsButIgnoresObjectKeyOrder(): void
    {
        $document = $this->document();
        $fingerprint = ImageProfile::fromArray($document)->fingerprint();
        $document['runtime'] = array_reverse($document['runtime'], true);
        self::assertSame($fingerprint, ImageProfile::fromArray($document)->fingerprint());
        $document['runtime']['nativeCommandLimit'] = 4;
        self::assertNotSame($fingerprint, ImageProfile::fromArray($document)->fingerprint());
    }

    public function testMissingBudgetCannotFallBackToFullDefaults(): void
    {
        $document = $this->document();
        unset($document['budgets']['controlMemoryMiB']);
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testUnknownProfileCannotBeHydrated(): void
    {
        $document = $this->document();
        $document['deployment']['identifier'] = 'unknown';
        $this->expectException(\InvalidArgumentException::class);
        ImageProfile::fromArray($document);
    }

    public function testResolveUsesTheCapturedDaemonAndImmutableImageWithoutNetwork(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $image = 'sha256:' . str_repeat('a', 64);
            $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
            $profile = ImageProfile::resolve($docker, $image, $layout, 'household', $directory . '/resources.json', getenv(), 5, memoryMiB: 512);
            self::assertSame(2304, $profile->controlMemoryMiB);
            $calls = file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($calls);
            $created = null;
            foreach ($calls as $line) {
                $arguments = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($arguments[0] === 'create') {
                    $created = $arguments;
                }
            }
            self::assertIsArray($created);
            self::assertContains($image, $created);
            self::assertSame('none', $created[array_search('--network', $created, true) + 1]);
            self::assertContains('--read-only', $created);
            self::assertSame('512m', $created[array_search('--memory', $created, true) + 1]);
            self::assertSame('512m', $created[array_search('--memory-swap', $created, true) + 1]);
            self::assertSame('/srv/baander', $created[array_search('--workdir', $created, true) + 1]);
            self::assertSame(['household', '/srv/baander'], array_slice($created, -2));
            self::assertFileDoesNotExist($directory . '/resources.json');
            self::assertSame([], glob($directory . '/resource-*.json'));
        });
    }

    public function testResolveRejectsAnImageReturningAnotherProfile(): void
    {
        $this->withDocker(function (Client $docker, string $directory): void {
            $this->expectException(\RuntimeException::class);
            ImageProfile::resolve($docker, 'sha256:' . str_repeat('a', 64), ApplicationLayout::load(), 'full', $directory . '/resources.json', getenv(), 5);
        });
    }

    private function withDocker(\Closure $test): void
    {
        $directory = sys_get_temp_dir() . '/baander-image-profile-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/daemon.json', ['stdout' => json_encode($this->document(), JSON_THROW_ON_ERROR)]);
        $executor = new EngineExecutor(ExecutionContext::bounded(10, getenv()));
        $docker = new Client($executor, new Target($directory . '/docker', 'unix:///baander.app/fixture.sock', 'owned-daemon', null, null), getenv());
        try {
            $test($docker, $directory);
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }
}
