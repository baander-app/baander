<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\JavaScriptDependencies\Check;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class JavaScriptDependencyCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-js-check-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/packages/baander-tooling/resources', 0700, true);
        mkdir($this->root . '/ui/web', 0700, true);
        $this->write('ui/web/package.json', ['name' => '@baander/web', 'devDependencies' => ['typescript' => '^6.0.3', 'failed' => '^1.0.0']]);
        file_put_contents($this->root . '/ui/web/yarn.lock', '__metadata: {}');
        $this->write('packages/baander-tooling/resources/javascript-dependencies.json', [
            'version' => 1,
            'scopeDirectories' => ['ui/web'],
            'resolutions' => [],
            'projects' => ['ui/web/package.json' => ['kind' => 'locked', 'manager' => 'yarn', 'lock' => 'ui/web/yarn.lock']],
            'patches' => [],
            'exceptions' => [['projects' => ['ui/web/package.json'], 'package' => 'typescript', 'declared' => ['^6.0.3'], 'reason' => 'Compiler peer range.']],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testOfflineMakesNoRequestsAndFindsUnknownManifestAndMissingLock(): void
    {
        mkdir($this->root . '/ui/web/new-package');
        $this->write('ui/web/new-package/package.json', ['name' => '@baander/new']);
        unlink($this->root . '/ui/web/yarn.lock');
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::never())->method('run');
        $report = (new Check($executor))->run($this->root);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('no scoped ownership', $report->render());
        self::assertStringContainsString('missing or empty', $report->render());
    }

    public function testMalformedManifestIsFinding(): void
    {
        file_put_contents($this->root . '/ui/web/package.json', '{');
        $report = (new Check($this->createStub(CommandExecutor::class)))->run($this->root);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('Syntax error', $report->render());
    }

    public function testChangedAuthoredPatchIsFinding(): void
    {
        mkdir($this->root . '/ui/web/.yarn/patches', 0700, true);
        file_put_contents($this->root . '/ui/web/.yarn/patches/fix.patch', 'changed');
        $path = 'packages/baander-tooling/resources/javascript-dependencies.json';
        $policy = json_decode(file_get_contents($this->root . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        $policy['patches'] = [['project' => 'ui/web/package.json', 'path' => 'ui/web/.yarn/patches/fix.patch', 'sha256' => hash('sha256', 'original'), 'reason' => 'Fix behavior.']];
        $this->write($path, $policy);
        $report = (new Check($this->createStub(CommandExecutor::class)))->run($this->root);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('unowned, changed', $report->render());
    }

    public function testOnlinePreservesBuiltinPatchExceptionAndAdvisoryWhenRegistryFails(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec): ProcessResult {
            self::assertGreaterThan(0, $spec->timeout);
            self::assertSame('false', $spec->environment['YARN_ENABLE_SCRIPTS']);
            $output = match (true) {
                in_array('info', $spec->argv, true) => '{"value":"typescript@patch:typescript@npm%3A6.0.3#optional!builtin<compat/typescript>","children":{"Version":"6.0.3"}}',
                in_array('audit', $spec->argv, true) => '{"value":"typescript","children":{"ID":42,"Severity":"high"}}',
                in_array('failed@latest', $spec->argv, true) => '{}',
                default => '{"version":"7.0.2","engines":{"node":">=24"}}',
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        $report = (new Check($executor))->run($this->root, false);
        self::assertSame(2, $report->exitCode());
        $rendered = $report->render(true);
        foreach (['builtin-patch', 'Compiler peer range.', '7.0.2', 'advisories', 'high', 'gap'] as $text) {
            self::assertStringContainsString($text, $rendered);
        }
    }

    public function testFailedResolvedRequestDoesNotEraseSuccessfulRegistryEvidence(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static fn(ProcessSpec $spec): ProcessResult => new ProcessResult(in_array('info', $spec->argv, true) ? 1 : 0, null, false, false, in_array('audit', $spec->argv, true) ? '' : '{"version":"7.0.2","engines":{"node":">=24"}}', '', 0.01));
        $report = (new Check($executor))->run($this->root, false);
        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('exited with status 1', $report->render());
        self::assertStringContainsString('7.0.2', $report->render());
    }

    public function testEmptyFailedAuditIsGap(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static fn(ProcessSpec $spec): ProcessResult => new ProcessResult(in_array('audit', $spec->argv, true) ? 1 : 0, null, false, false, '', '', 0.01));
        $report = (new Check($executor))->run($this->root, false);
        self::assertStringContainsString('Audit failed without advisory evidence', $report->render());
        self::assertSame(2, $report->exitCode());
    }

    public function testLatestTransitiveDoesNotHideOlderDirectVersion(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec): ProcessResult {
            $output = match (true) {
                in_array('info', $spec->argv, true) => implode("\n", [
                    '{"value":"@baander/web@workspace:.","children":{"Version":"1.0.0","Dependencies":[{"descriptor":"typescript@npm:^6.0.3","locator":"typescript@npm:6.0.3"}]}}',
                    '{"value":"typescript@npm:6.0.3","children":{"Version":"6.0.3"}}',
                    '{"value":"typescript@npm:7.0.2","children":{"Version":"7.0.2"}}',
                ]),
                in_array('audit', $spec->argv, true) => '',
                default => '"7.0.2"',
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        $report = (new Check($executor))->run($this->root, false);
        $records = array_values(array_filter($report->toArray()['records'], static fn(array $record): bool => $record['kind'] === 'freshness' && ($record['details']['package'] ?? null) === 'typescript'));
        self::assertSame('finding', $records[0]['status']);
        self::assertSame(['6.0.3'], $records[0]['details']['resolved']);
    }

    public function testInternalAndLocalPackagesAreNotQueried(): void
    {
        $this->write('ui/web/package.json', ['name' => '@baander/web', 'dependencies' => ['@baander/web' => '*', 'local-package' => 'file:../local']]);
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::exactly(2))->method('run')->willReturnCallback(static function (ProcessSpec $spec): ProcessResult {
            self::assertNotContains('view', $spec->argv);
            return new ProcessResult(0, null, false, false, in_array('info', $spec->argv, true) ? '{"value":"@baander/web@workspace:.","children":{"Version":"1.0.0"}}' : '', '', 0.01);
        });
        self::assertSame(0, (new Check($executor))->run($this->root, false)->exitCode());
    }

    public function testNpmLockTreeAndAdvisoryEvidenceAreCollected(): void
    {
        $policyPath = 'packages/baander-tooling/resources/javascript-dependencies.json';
        $policy = json_decode(file_get_contents($this->root . '/' . $policyPath), true, flags: JSON_THROW_ON_ERROR);
        $policy['projects']['ui/web/package.json'] = ['kind' => 'locked', 'manager' => 'npm', 'lock' => 'ui/web/package-lock.json'];
        $this->write($policyPath, $policy);
        $this->write('ui/web/package-lock.json', ['packages' => ['' => ['devDependencies' => ['typescript' => '^6.0.3', 'failed' => '^1.0.0']]]]);
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec): ProcessResult {
            $output = match (true) {
                in_array('ls', $spec->argv, true) => '{"dependencies":{"typescript":{"version":"6.0.3"},"failed":{"version":"1.0.0"}}}',
                in_array('audit', $spec->argv, true) => '{"vulnerabilities":{"failed":{"severity":"high"}}}',
                default => '{"version":"7.0.2"}',
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        $report = (new Check($executor))->run($this->root, false);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('high', $report->render());
        self::assertStringContainsString('6.0.3', $report->render());
    }

    public function testIncompletePolicyFailsClosedBeforeAnyProcess(): void
    {
        $this->write('packages/baander-tooling/resources/javascript-dependencies.json', ['version' => 1]);
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::never())->method('run');
        $report = (new Check($executor))->run($this->root, false);
        self::assertSame(2, $report->exitCode());
        self::assertSame('gap', $report->toArray()['records'][0]['status']);
    }

    public function testWrongPolicyShapesFailClosedBeforeAnyProcess(): void
    {
        $path = 'packages/baander-tooling/resources/javascript-dependencies.json';
        $valid = json_decode(file_get_contents($this->root . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        $invalid = [
            [...$valid, 'version' => 2],
            [...$valid, 'scopeDirectories' => 'ui/web'],
            [...$valid, 'projects' => []],
            [...$valid, 'projects' => ['ui/web/package.json' => ['kind' => 'unknown']]],
            [...$valid, 'projects' => ['ui/web/package.json' => ['kind' => 'locked', 'manager' => 'unknown', 'lock' => 'ui/web/yarn.lock']]],
            [...$valid, 'projects' => ['ui/web/package.json' => ['kind' => 'locked', 'manager' => 'yarn']]],
            [...$valid, 'exceptions' => [['package' => 'typescript']]],
            [...$valid, 'patches' => [['path' => 'fix.patch']]],
            [...$valid, 'resolutions' => ['ui/web/package.json' => ['typescript' => '7.0.2']]],
        ];
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::never())->method('run');
        foreach ($invalid as $policy) {
            $this->write($path, $policy);
            self::assertSame(2, (new Check($executor))->run($this->root, false)->exitCode());
        }
    }

    public function testOrdinaryPublicDependencyHasCleanYarnOnlineReport(): void
    {
        $this->assertCleanOnlineReport('yarn');
    }

    public function testOrdinaryPublicDependencyHasCleanNpmOnlineReport(): void
    {
        $this->assertCleanOnlineReport('npm');
    }

    private function assertCleanOnlineReport(string $manager): void
    {
        $this->write('ui/web/package.json', ['name' => '@baander/web', 'dependencies' => ['axios' => '^1.21.0']]);
        if ($manager === 'npm') {
            $path = 'packages/baander-tooling/resources/javascript-dependencies.json';
            $policy = json_decode(file_get_contents($this->root . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
            $policy['projects']['ui/web/package.json'] = ['kind' => 'locked', 'manager' => 'npm', 'lock' => 'ui/web/package-lock.json'];
            $this->write($path, $policy);
            $this->write('ui/web/package-lock.json', ['packages' => ['' => ['dependencies' => ['axios' => '^1.21.0']]]]);
        }
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::exactly(3))->method('run')->willReturnCallback(static function (ProcessSpec $spec) use ($manager): ProcessResult {
            $output = match (true) {
                in_array('info', $spec->argv, true) => implode("\n", [
                    '{"value":"@baander/web@workspace:.","children":{"Version":"1.0.0","Dependencies":[{"descriptor":"axios@npm:^1.21.0","locator":"axios@npm:1.21.0"}]}}',
                    '{"value":"axios@npm:1.21.0","children":{"Version":"1.21.0"}}',
                ]),
                in_array('ls', $spec->argv, true) => '{"dependencies":{"axios":{"version":"1.21.0"}}}',
                in_array('audit', $spec->argv, true) => $manager === 'yarn' ? '' : '{"vulnerabilities":{}}',
                default => '{"version":"1.21.0"}',
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        $report = (new Check($executor))->run($this->root, false);
        self::assertSame(0, $report->exitCode());
        $records = $report->toArray()['records'];
        self::assertNotContains('gap', array_column($records, 'status'));
        $freshness = array_values(array_filter($records, static fn(array $record): bool => $record['kind'] === 'freshness'));
        self::assertCount(1, $freshness);
        self::assertSame('evidence', $freshness[0]['status']);
        self::assertSame(['1.21.0'], $freshness[0]['details']['resolved']);
        self::assertSame('1.21.0', $freshness[0]['details']['latest']);
    }

    /** @param array<string,mixed> $value */
    private function write(string $path, array $value): void
    {
        file_put_contents($this->root . '/' . $path, json_encode($value, JSON_THROW_ON_ERROR));
    }
}
