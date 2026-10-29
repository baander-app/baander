<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Development\{ReleaseContract, StateStore, WorkflowError};
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentReleaseInputIntegrityTest extends TestCase
{
    private string $root;
    private StateStore $store;
    /** @var array<string,mixed> */
    private array $instance;
    /** @var array<string,mixed> */
    private array $release;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-release-integrity-' . bin2hex(random_bytes(8));
        PrivateDirectory::prepare($this->root . '/source/src');
        PrivateDirectory::prepare($this->root . '/source/migrations');
        PrivateDirectory::prepare($this->root . '/source/bin');
        AtomicFile::write($this->root . '/source/Dockerfile', "FROM php:8.5\nWORKDIR /srv/baander\n");
        AtomicFile::write($this->root . '/source/bin/console', '<?php // console');
        AtomicFile::write($this->root . '/source/src/Entry.php', '<?php // original application');
        AtomicFile::write($this->root . '/source/migrations/Version20261029000000.php', '<?php // original migration');
        symlink('../bin/console', $this->root . '/source/src/console-link');
        $this->store = new StateStore($this->root . '/state');
        $this->instance = [
            'id' => 'preview-0123456789abcdef', 'project' => 'release-integrity',
            'docker' => new Target('/bin/true', 'unix:///tmp/baander.app-integrity.sock', 'saved-daemon', null, null)->toArray(),
            'media' => [['source' => $this->root . '/missing-media', 'target' => '/media/movies']],
            'libraries' => [['slug' => 'movies', 'name' => 'Movies', 'type' => 'movies', 'source' => $this->root . '/missing-media', 'target' => '/media/movies']],
            'state_mounts' => ['storage' => ['type' => 'volume', 'source' => 'retained-storage', 'target' => '/srv/baander/storage']],
            'volumes' => ['database' => 'retained-database', 'storage' => 'retained-storage'],
            'postgres_image' => 'sha256:retained-postgres', 'redis_image' => 'sha256:retained-redis',
        ];
        AtomicFile::write($this->store->directory($this->instance['id']) . '/env.json', "{\"APP_SECRET\":\"retained-private-fixture\"}\n");
        $this->release = ['revision' => 'materialized-revision', 'source' => $this->root . '/source',
            'layout' => ['version' => 1, 'root' => '/srv/baander']];
        foreach (ReleaseContract::IMAGE_KEYS as $key) {
            $this->release[$key] = 'sha256:' . hash('sha256', $key);
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function prepared(): ReleaseContract
    {
        return ReleaseContract::prepared($this->release, $this->instance, ToolingConfiguration::resolve(), $this->store);
    }

    public function testUnchangedMaterializedSourceAndPrivateEnvironmentCanResume(): void
    {
        $contract = $this->prepared();
        $environment = $this->store->directory($this->instance['id']) . '/env.json';
        $data = $contract->toArray();
        self::assertSame($environment, $data['environmentFile']);
        self::assertSame(hash_file('sha256', $environment), $data['inputs'][$environment]);
        self::assertEquals(['type' => 'file', 'digest' => hash('sha256', '<?php // original migration'), 'mode' => 0600], $data['sourceIdentity']['migrations/Version20261029000000.php']);
        self::assertEquals(['type' => 'link', 'digest' => hash('sha256', '../bin/console'), 'mode' => 0777], $data['sourceIdentity']['src/console-link']);
        self::assertSame(hash('sha256', json_encode($data['sourceIdentity'], JSON_THROW_ON_ERROR)), $data['sourceDigest']);
        $contract->validateInputs($this->instance);
        self::assertDirectoryDoesNotExist($this->root . '/missing-media');
    }

    public function testSavedContractSurvivesAtomicJsonCanonicalization(): void
    {
        $path = $this->root . '/saved-release.json';
        AtomicFile::writeJson($path, $this->prepared()->toArray());
        $contract = ReleaseContract::fromArray(AtomicFile::readJson($path));
        $contract->validateInputs($this->instance);
        self::assertSame('materialized-revision', $contract->toArray()['revision']);
    }

    /** @return iterable<string,array{string}> */
    public static function sourceChanges(): iterable
    {
        yield 'application edit' => ['application'];
        yield 'migration edit' => ['migration'];
        yield 'source file removed' => ['removed'];
        yield 'source file added' => ['added'];
        yield 'source permissions changed' => ['mode'];
        yield 'source link changed' => ['link'];
    }

    #[DataProvider('sourceChanges')]
    public function testMaterializedSourceChangesBlockActivation(string $change): void
    {
        $contract = $this->prepared();
        if ($change === 'application') {
            file_put_contents($this->root . '/source/src/Entry.php', '<?php // changed application');
        } elseif ($change === 'migration') {
            file_put_contents($this->root . '/source/migrations/Version20261029000000.php', '<?php // changed migration');
        } elseif ($change === 'removed') {
            unlink($this->root . '/source/src/Entry.php');
        } elseif ($change === 'mode') {
            chmod($this->root . '/source/src/Entry.php', 0640);
        } elseif ($change === 'link') {
            unlink($this->root . '/source/src/console-link');
            symlink('Entry.php', $this->root . '/source/src/console-link');
        } else {
            file_put_contents($this->root . '/source/src/Added.php', '<?php // added after preparation');
        }
        $this->expectException(WorkflowError::class);
        $contract->validateInputs($this->instance);
    }

    public function testChangedGeneratedArtifactBlocksMatchingPreparedActivation(): void
    {
        $contract = $this->prepared();
        $compose = $this->root . '/compose.json';
        AtomicFile::write($compose, '{"services":{}}');
        $instance = $this->instance + ['generated_inputs' => ['release' => $contract->toArray()['sourceDigest'],
            'files' => [$compose => hash_file('sha256', $compose)]]];
        AtomicFile::write($compose, '{"services":{"unexpected":{}}}');
        $this->expectExceptionMessage('Saved Compose or frontend configuration changed');
        $contract->validateInputs($instance);
    }

    public function testGeneratedOwnershipCanBeValidatedWithMissingSourceAndMedia(): void
    {
        $contract = $this->prepared();
        $compose = $this->root . '/compose.json';
        AtomicFile::write($compose, '{"services":{}}');
        $instance = $this->instance + ['generated_inputs' => ['release' => $contract->toArray()['sourceDigest'],
            'files' => [$compose => hash_file('sha256', $compose)]]];
        rename($this->root . '/source', $this->root . '/source-unavailable');
        ReleaseContract::validateGeneratedInputs($instance);
        self::assertDirectoryDoesNotExist($this->root . '/source');
        self::assertDirectoryDoesNotExist($this->root . '/missing-media');
    }

    public function testPrivateEnvironmentChangeBlocksActivation(): void
    {
        $contract = $this->prepared();
        AtomicFile::write($this->store->directory($this->instance['id']) . '/env.json', "{\"APP_SECRET\":\"changed-identity\"}\n");
        $this->expectException(WorkflowError::class);
        $contract->validateInputs($this->instance);
    }

    public function testPreparedEffectiveConfigurationCapturesRetainedMountsAndServicePins(): void
    {
        $effective = $this->prepared()->toArray()['effective'];
        foreach (['state_mounts', 'volumes', 'postgres_image', 'redis_image'] as $key) {
            self::assertSame($this->instance[$key], $effective[$key] ?? null);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function incompleteInventories(): iterable
    {
        yield 'source entry omitted' => ['source'];
        yield 'all source entries omitted' => ['empty-source'];
        yield 'private environment omitted' => ['environment'];
    }

    #[DataProvider('incompleteInventories')]
    public function testIncompleteInventoryCannotBecomeAValidSavedContract(string $missing): void
    {
        $data = $this->prepared()->toArray();
        if ($missing === 'source') {
            unset($data['sourceIdentity']['src/Entry.php']);
        } elseif ($missing === 'empty-source') {
            $data['sourceIdentity'] = [];
            $data['sourceDigest'] = hash('sha256', json_encode([], JSON_THROW_ON_ERROR));
        } else {
            unset($data['inputs'][$data['environmentFile']]);
        }
        $this->expectException(WorkflowError::class);
        ReleaseContract::fromArray($data);
    }

    public function testEmptyInputInventoryCannotBecomeAValidSavedContract(): void
    {
        $data = $this->prepared()->toArray();
        $data['inputs'] = [];
        $this->expectException(WorkflowError::class);
        ReleaseContract::fromArray($data);
    }
}
