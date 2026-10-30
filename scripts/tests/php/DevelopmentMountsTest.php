<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Development\Mounts;
use Baander\Tooling\Development\WorkflowError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentMountsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-mount-preflight-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rmdir($this->directory . '/' . $entry);
            }
        }
        rmdir($this->directory);
    }

    private function mounts(): Mounts
    {
        return new Mounts(ApplicationLayout::fromArray(['version' => 1, 'root' => '/opt/baander']));
    }

    public function testNormalizesPathsWhilePreservingLibraryIdentity(): void
    {
        $record = ['slug' => 'movies', 'name' => 'Movies', 'id' => 'retained-id', 'source' => $this->directory . '/./', 'target' => '/media/other/../movies//'];
        self::assertSame([
            [...$record, 'source' => $this->directory, 'target' => '/media/movies'],
        ], $this->mounts()->preflight([$record]));
    }

    /** @return iterable<string,array{string}> */
    public static function reservedTargets(): iterable
    {
        foreach (['/', '/opt', '/opt/baander/var', '/media/../opt/baander', '/storage', '/storage/conversions', '/storage/conversions/nested', '/storage/media/nested', '/etc/baander', '/run/socket', '/var/run/socket', '/proc/files', '/sys', '/dev/zero', '/data', '/var/lib/postgresql/data'] as $target) {
            yield $target => [$target];
        }
    }

    #[DataProvider('reservedTargets')]
    public function testRejectsNormalizedReservedOverlaps(string $target): void
    {
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([['source' => $this->directory, 'target' => $target]]);
    }

    public function testRejectsOverlapAfterNormalizingMounts(): void
    {
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([
            ['source' => $this->directory, 'target' => '/media/movies'],
            ['source' => $this->directory, 'target' => '/media/music/../movies/children'],
        ]);
    }

    public function testCustomConversionAndAdoptedRuntimeMountsAreReserved(): void
    {
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([['source' => $this->directory, 'target' => '/saved']], environment: ['CONVERT_STORAGE_PATH' => '/saved/conversions']);
    }

    public function testExplicitRuntimeTargetsAreReserved(): void
    {
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([['source' => $this->directory, 'target' => '/retained/data']], reservedTargets: ['/retained']);
    }

    public function testDefaultMediaMountRemainsAnIntentionalReplacement(): void
    {
        $mount = ['source' => $this->directory, 'target' => '/storage/media'];
        self::assertSame([$mount], $this->mounts()->preflight([$mount]));
    }

    public function testRejectsCommaSourceBeforeDockerMountParsing(): void
    {
        mkdir($this->directory . '/media,readonly');
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([['source' => $this->directory . '/media,readonly', 'target' => '/media/movies']]);
    }

    public function testRejectsControlCharactersInExistingSource(): void
    {
        mkdir($this->directory . "/media\nreadonly");
        $this->expectException(WorkflowError::class);
        $this->mounts()->preflight([['source' => $this->directory . "/media\nreadonly", 'target' => '/media/movies']]);
    }

    public function testRejectsStateThroughSourceSymlinks(): void
    {
        mkdir($this->directory . '/state');
        symlink($this->directory . '/state', $this->directory . '/media');
        try {
            $this->expectException(WorkflowError::class);
            $this->mounts()->preflight([['source' => $this->directory . '/media', 'target' => '/media/movies']], $this->directory . '/state');
        } finally {
            unlink($this->directory . '/media');
        }
    }
}
