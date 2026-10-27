<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Docker;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use PHPUnit\Framework\TestCase;

final class DevelopmentStateTest extends TestCase
{
    private string $root;
    private StateStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dev-core-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->store = new StateStore($this->root . '/state');
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    public function testIdentityMatchesExistingDigestAndResolvesSymlinks(): void
    {
        mkdir($this->root . '/work');
        symlink($this->root . '/work', $this->root . '/alias');
        $expected = 'preview-' . substr(hash('sha256', "repository\0" . $this->root . '/work'), 0, 16);
        self::assertSame($expected, Environment::previewId($this->root . '/alias', 'repository'));
        self::assertNotSame($expected, Environment::previewId($this->root . '/work', 'other'));
        self::assertNotSame($expected, Environment::previewId($this->root . '/other', 'repository'));
    }

    public function testMissingPreviewNeverSelectsPersistent(): void
    {
        $this->store->save('persistent', ['id' => 'persistent']);
        self::assertSame('persistent', $this->store->resolveInstance($this->root, 'repository', 'persistent')['id']);
        $this->expectExceptionMessage('preview-up');
        $this->store->resolveInstance($this->root, 'repository');
    }

    public function testRecordsReopenWithoutChangingSchemaOrIndependentIntent(): void
    {
        $record = ['id' => 'persistent', 'phase' => 'database_mutating', 'repository' => 'repository',
            'environment' => ['APP_SECRET' => 'private'], 'checkpoint' => ['directory' => '/retained/checkpoint', 'verified' => true],
            'media' => [['source' => '/media', 'target' => '/var/media']], 'running_revision' => null];
        $this->store->save('persistent', $record);
        $this->store->setEnabled('persistent', true);
        $lock = $this->store->lock('persistent');
        try {
            $this->store->setEnabled('persistent', false);
            self::assertFalse($this->store->enabled('persistent'));
            self::assertEquals($record, new StateStore($this->store->root)->load('persistent'));
        } finally {
            $lock->release();
        }
        self::assertSame(0600, fileperms($this->store->directory('persistent') . '/state.json') & 0777);
        self::assertSame(0700, fileperms($this->store->directory('persistent')) & 0777);
    }

    public function testCorruptOrMismatchedRecordsRemainForRecovery(): void
    {
        $this->store->save('persistent', ['id' => 'persistent']);
        $path = $this->store->directory('persistent') . '/state.json';
        foreach (['{', '{"id":"elsewhere"}', '[]'] as $content) {
            file_put_contents($path, $content);
            try {
                $this->store->load('persistent');
                self::fail('Invalid record accepted');
            } catch (WorkflowError) {
                self::assertSame($content, file_get_contents($path));
            }
        }
    }

    public function testResavingExistingRecordPreservesEmptyObjectAndListShapes(): void
    {
        $this->store->save('persistent', ['id' => 'persistent']);
        $path = $this->store->directory('persistent') . '/state.json';
        file_put_contents($path, '{"id":"persistent","custom":{"mapping":{},"list":[]},"environment":{},"migration_hashes":{}}');
        $record = $this->store->load('persistent');
        $this->store->save('persistent', $record);
        $decoded = json_decode((string) file_get_contents($path));
        self::assertInstanceOf(\stdClass::class, $decoded->custom->mapping);
        self::assertSame([], $decoded->custom->list);
        self::assertInstanceOf(\stdClass::class, $decoded->environment);
        self::assertInstanceOf(\stdClass::class, $decoded->migration_hashes);
    }

    public function testTraversalAndSymlinksCannotEscapeState(): void
    {
        foreach (['../outside', '/tmp/outside', '', 'PERSISTENT'] as $identity) {
            try {
                $this->store->directory($identity);
                self::fail('Invalid identity accepted');
            } catch (WorkflowError) {
                self::assertDirectoryDoesNotExist($this->store->root);
            }
        }
        mkdir($this->store->root);
        symlink($this->root, $this->store->root . '/persistent');
        $this->expectException(WorkflowError::class);
        $this->store->save('persistent', ['id' => 'persistent']);
    }

    public function testSymlinkIntentIsRefusedEvenWhenDangling(): void
    {
        $this->store->save('persistent', ['id' => 'persistent']);
        symlink($this->root . '/missing', $this->store->directory('persistent') . '/intent.json');
        $this->expectException(WorkflowError::class);
        $this->store->enabled('persistent');
    }

    public function testEnvironmentKeepsOnlyExplicitTargetingAndSafeAmbientKeys(): void
    {
        self::assertSame(['PATH' => '/usr/bin', 'HOME' => '/home/developer', 'SSH_AUTH_SOCK' => '/agent'], Environment::clean([
            'PATH' => '/usr/bin', 'HOME' => '/home/developer', 'SSH_AUTH_SOCK' => '/agent', 'DATABASE_URL' => 'secret',
            'DOCKER_HOST' => 'tcp://baander.app:2375', 'COMPOSE_FILE' => '/other', 'APP_SECRET' => 'private', 'GIT_DIR' => '/other']));
        putenv('COMPOSE_PROJECT_NAME=wrong');
        try {
            $result = new Runner()->run([PHP_BINARY, '-r', 'echo json_encode([getenv("DATABASE_URL"),getenv("COMPOSE_PROJECT_NAME")]);'], env: ['DATABASE_URL' => 'selected']);
            self::assertSame(['selected', false], json_decode($result->stdout, true));
        } finally {
            putenv('COMPOSE_PROJECT_NAME');
        }
    }

    public function testProcessLockRefusesMutationWhileStatusRemainsReadable(): void
    {
        $this->store->save('persistent', ['id' => 'persistent', 'phase' => 'preparing']);
        $lock = $this->store->lock('persistent');
        try {
            $result = new Runner()->run([PHP_BINARY, __DIR__ . '/Fixtures/dev-core-lock.php', $this->store->root], check: false);
            self::assertSame(2, $result->exitCode);
            self::assertSame("preparing\n", $result->stdout);
            self::assertStringContainsString('another operation', $result->stderr);
        } finally {
            $lock->release();
        }
    }

    public function testCommandFailureDoesNotPublishArgumentsOrOutput(): void
    {
        try {
            new Runner()->run([PHP_BINARY, '-r', 'fwrite(STDERR,"secret-output");exit(3);', 'secret-argument']);
            self::fail('Failure accepted');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('status 3', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function testDockerPinsContextAndValidatesDaemonIdentity(): void
    {
        $runner = new class extends Runner {
            /** @var list<non-empty-list<string>> */
            public array $seen = [];
            public function run(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null,
                ?float $timeout = 120, bool $check = true, ?string $logPath = null, bool $interactive = false): \Baander\Tooling\Process\ProcessResult
            {
                $this->seen[] = $arguments;
                return new \Baander\Tooling\Process\ProcessResult(0, null, false, false, '{"ID":"daemon"}', '', 0);
            }
        };
        $docker = new Docker($runner, ['executable' => '/selected/docker', 'context' => 'selected', 'daemon_id' => 'daemon']);
        self::assertSame(['ID' => 'daemon'], $docker->validate());
        $docker->compose(['project' => 'selected-project', 'compose_file' => '/selected/compose.json'], ['up', '-d']);
        self::assertSame(['/selected/docker', '--context', 'selected', 'compose', '--project-name', 'selected-project', '--file', '/selected/compose.json', 'up', '-d'], $runner->seen[1]);
        $this->expectExceptionMessage('identity changed');
        new Docker($runner, ['executable' => '/selected/docker', 'context' => 'selected', 'daemon_id' => 'other'])->validate();
    }
}
