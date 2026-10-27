<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Build\Source;
use Baander\Tooling\Development\Build\SourceUnavailable;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class DevelopmentBuildTest extends TestCase
{
    private string $root;
    private string $repo;
    private Runner $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-source-' . bin2hex(random_bytes(8));
        $this->repo = $this->root . '/repo';
        mkdir($this->repo . '/src', 0700, true);
        $this->runner = new Runner();
        $this->git(['init', '-b', 'master']);
        $this->git(['config', 'user.email', 'dev@baander.app']);
        $this->git(['config', 'user.name', 'Development fixture']);
        file_put_contents($this->repo . '/src/code.php', 'original');
        file_put_contents($this->repo . '/.env', 'APP_SECRET=template');
        file_put_contents($this->repo . '/.gitignore', ".env.local\nvar/\n");
        file_put_contents($this->repo . '/execute', '#!/bin/sh');
        chmod($this->repo . '/execute', 0755);
        symlink('src/code.php', $this->repo . '/linked');
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'fixture']);
    }

    protected function tearDown(): void
    {
        $this->runner->run(['rm', '-rf', '--', $this->root]);
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        return trim($this->runner->run(['git', ...$args], cwd: $this->repo)->stdout);
    }

    public function testPreviewCapturesEditsWithoutSecretsAndHasStableDigest(): void
    {
        file_put_contents($this->repo . '/src/code.php', 'edited');
        file_put_contents($this->repo . '/src/new.php', 'new');
        file_put_contents($this->repo . '/.env', 'APP_SECRET=actual-secret');
        file_put_contents($this->repo . '/.env.local', 'private');
        mkdir($this->repo . '/var');
        file_put_contents($this->repo . '/var/state', 'state');
        $first = Source::snapshotWorktree($this->runner, $this->repo, $this->root . '/snapshot');
        self::assertSame('edited', file_get_contents($this->root . '/snapshot/src/code.php'));
        self::assertSame('APP_SECRET=template', file_get_contents($this->root . '/snapshot/.env'));
        self::assertFileDoesNotExist($this->root . '/snapshot/.env.local');
        self::assertDirectoryDoesNotExist($this->root . '/snapshot/var');
        self::assertDirectoryDoesNotExist($this->root . '/snapshot/.git');
        self::assertSame('src/code.php', readlink($this->root . '/snapshot/linked'));
        self::assertSame(0755, fileperms($this->root . '/snapshot/execute') & 0777);
        self::assertSame($first, Source::snapshotWorktree($this->runner, $this->repo, null));
        file_put_contents($this->repo . '/src/code.php', 'newer');
        self::assertNotSame($first, Source::snapshotWorktree($this->runner, $this->repo, null));
    }

    public function testExternalSymlinkIsRejected(): void
    {
        symlink($this->root, $this->repo . '/src/escape');
        $this->expectException(WorkflowError::class);
        Source::snapshotWorktree($this->runner, $this->repo, $this->root . '/snapshot');
    }

    public function testSnapshotIncludesOnlyTheExecutableProseAuditWithinAgentFiles(): void
    {
        $relative = '.agents/skills/prose-fix/scripts/audit_rewrite.php';
        $helper = $this->repo . '/' . $relative;
        mkdir(dirname($helper), 0700, true);
        copy(dirname(__DIR__, 3) . '/' . $relative, $helper);
        chmod($helper, 0750);
        $secrets = [
            '.agents/session-state.json',
            '.agents/skills/prose-fix/scripts/audit_rewrite.php.local',
            '.agents/skills/prose-fix/scripts/private.php',
            '.agents/skills/other/scripts/audit_rewrite.php',
            '.codex/state.json',
        ];
        foreach ($secrets as $secret) {
            $path = $this->repo . '/' . $secret;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, 'private agent state');
        }
        $this->git(['add', $relative, '.agents/session-state.json']);
        $snapshot = $this->root . '/snapshot';
        $digest = Source::snapshotWorktree($this->runner, $this->repo, $snapshot);
        self::assertSame(file_get_contents($helper), file_get_contents($snapshot . '/' . $relative));
        self::assertSame(0750, fileperms($snapshot . '/' . $relative) & 0777);
        foreach ($secrets as $secret) {
            self::assertFileDoesNotExist($snapshot . '/' . $secret);
        }
        file_put_contents($this->repo . '/.agents/session-state.json', 'changed private state');
        self::assertSame($digest, Source::snapshotWorktree($this->runner, $this->repo, null));
        file_put_contents($helper, "\n// changed worktree helper\n", FILE_APPEND);
        self::assertNotSame($digest, Source::snapshotWorktree($this->runner, $this->repo, null));
        rename($this->repo . '/.agents', $this->root . '/original-agents');
        $baseline = $this->root . '/baseline.md';
        file_put_contents($baseline, "# Fixture\n\nKeep `publicId` and 32 bytes.\n");
        $result = $this->runner->run([PHP_BINARY, $snapshot . '/' . $relative, $baseline, $baseline]);
        self::assertStringContainsString('structural lines: identical', $result->stdout);
    }

    public function testAllowedAuditPathCannotReadThroughASymlinkedAgentDirectory(): void
    {
        $directory = $this->repo . '/.agents/skills/prose-fix/scripts';
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/audit_rewrite.php', 'tracked helper');
        $this->git(['add', '.agents']);
        unlink($directory . '/audit_rewrite.php');
        rmdir($directory);
        $private = $this->root . '/private-agent-state';
        mkdir($private, 0700);
        file_put_contents($private . '/audit_rewrite.php', 'private secret');
        symlink($private, $directory);
        $this->expectExceptionMessage('without symlink components');
        Source::snapshotWorktree($this->runner, $this->repo, null);
    }

    public function testDanglingExternalAndCyclicSymlinksAreRejected(): void
    {
        foreach (['../../missing-outside', 'escape'] as $link) {
            symlink($link, $this->repo . '/src/escape');
            try {
                Source::snapshotWorktree($this->runner, $this->repo, null);
                self::fail('Expected symlink refusal');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('symlink', $error->getMessage());
            } finally {
                unlink($this->repo . '/src/escape');
            }
        }
    }

    public function testManagedCheckoutExportsRemoteCommitWithModesAndLinks(): void
    {
        $revision = $this->git(['rev-parse', 'HEAD']);
        file_put_contents($this->repo . '/src/code.php', 'dirty');
        [$source, $resolved] = Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/release');
        self::assertSame($revision, $resolved);
        self::assertSame('original', file_get_contents($source . '/src/code.php'));
        self::assertSame('dirty', file_get_contents($this->repo . '/src/code.php'));
        self::assertSame(0111, fileperms($source . '/execute') & 0111);
        self::assertSame('src/code.php', readlink($source . '/linked'));
    }

    public function testPartialCloneIsRemovedAndRetrySucceeds(): void
    {
        $runner = new class extends Runner {
            public function run(
                array $arguments,
                ?string $cwd = null,
                ?array $env = null,
                ?string $input = null,
                ?float $timeout = 120,
                bool $check = true,
                ?string $logPath = null,
                bool $interactive = false,
            ): ProcessResult {
                if (array_slice($arguments, 0, 2) === ['git', 'clone']) {
                    mkdir($arguments[array_key_last($arguments)]);
                    throw new WorkflowError('transport interrupted');
                }
                return parent::run($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
            }
        };
        try {
            Source::checkoutRevision($runner, $this->repo, $this->root . '/managed', $this->root . '/release');
            self::fail('Expected source failure');
        } catch (SourceUnavailable) {
            self::assertDirectoryDoesNotExist($this->root . '/managed');
            self::assertSame([], glob($this->root . '/.clone-*'));
        }
        [, $revision] = Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/release');
        self::assertSame($this->git(['rev-parse', 'HEAD']), $revision);
    }

    public function testWrongManagedRemoteRemainsDeterministic(): void
    {
        Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/first');
        $this->expectExceptionMessage('different remote');
        Source::checkoutRevision($this->runner, $this->root . '/other', $this->root . '/managed', $this->root . '/release');
    }

    public function testTransientFetchRetainsCheckoutAndAllowsSameRevisionRetry(): void
    {
        Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/first');
        $runner = new class extends Runner {
            public function run(
                array $arguments,
                ?string $cwd = null,
                ?array $env = null,
                ?string $input = null,
                ?float $timeout = 120,
                bool $check = true,
                ?string $logPath = null,
                bool $interactive = false,
            ): ProcessResult {
                if (array_slice($arguments, 0, 2) === ['git', 'fetch']) {
                    throw new WorkflowError('transport interrupted');
                }
                return parent::run($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
            }
        };
        try {
            Source::checkoutRevision($runner, $this->repo, $this->root . '/managed', $this->root . '/release');
            self::fail('Expected source failure');
        } catch (SourceUnavailable) {
            self::assertDirectoryExists($this->root . '/managed/.git');
            self::assertDirectoryDoesNotExist($this->root . '/release');
        }
        [, $revision] = Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/release');
        self::assertSame($this->git(['rev-parse', 'HEAD']), $revision);
    }

    public function testRemoteArchiveRefusesAbsoluteLinksBeforeExtractingFiles(): void
    {
        symlink('/outside-worktree', $this->repo . '/absolute-link');
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'absolute symlink fixture']);
        try {
            Source::checkoutRevision($this->runner, $this->repo, $this->root . '/managed', $this->root . '/release');
            self::fail('Expected unsafe archive refusal');
        } catch (\Baander\Tooling\Inventory\InventoryError $error) {
            self::assertStringContainsString('absolute', $error->getMessage());
            self::assertSame(['.', '..'], scandir($this->root . '/release'));
        }
    }
}
