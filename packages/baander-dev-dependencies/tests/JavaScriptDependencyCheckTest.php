<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Tests;

use Baander\DevDependencies\JavaScript\Check;
use Baander\DevDependencies\JavaScript\Command;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JavaScriptDependencyCheckTest extends TestCase
{
    private const string POLICY = 'packages/baander-dev-dependencies/resources/javascript-dependencies.json';
    private const string MINIMATCH_ADVISORY = '{"value":"minimatch","children":{"ID":1244623,"Issue":"minimatch has potential denial of service via globparts amplification","URL":"https://github.com/advisories/GHSA-g3hv-75wg-m249","Severity":"moderate","Vulnerable Versions":">=7.0.0 <11.0.1","Tree Versions":["10.2.6"],"Dependents":["eslint@npm:10.14.0"]}}';
    private const string DEPRECATION = '{"value":"tldts-core","children":{"ID":"tldts-core (deprecation)","Issue":"Partial release, see https://github.com/remusao/tldts/issues/2735. Use >=7.4.22.","Severity":"moderate","Vulnerable Versions":"7.4.20","Tree Versions":["7.4.20"],"Dependents":["tldts@npm:7.4.19"]}}';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-js-check-' . (random_bytes(8) |> bin2hex(...));
        mkdir($this->root . '/packages/baander-dev-dependencies/resources', 0700, true);
        mkdir($this->root . '/ui/web', 0700, true);
        $this->write('ui/web/package.json', ['name' => '@baander/web', 'devDependencies' => ['typescript' => '^6.0.3', 'failed' => '^1.0.0']]);
        file_put_contents($this->root . '/ui/web/yarn.lock', '__metadata: {}');
        $this->write('packages/baander-dev-dependencies/resources/javascript-dependencies.json', [
            'version' => 1,
            'scopeDirectories' => ['ui/web'],
            'resolutions' => [],
            'projects' => ['ui/web/package.json' => ['kind' => 'locked', 'manager' => 'yarn', 'lock' => 'ui/web/yarn.lock']],
            'patches' => [],
            'exceptions' => [['projects' => ['ui/web/package.json'], 'package' => 'typescript', 'declared' => ['^6.0.3'], 'reason' => 'Compiler peer range.']],
            'advisoryExceptions' => [],
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
        $report = new Check($executor)->run($this->root);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('no scoped ownership', $report->render());
        self::assertStringContainsString('missing or empty', $report->render());
    }

    public function testMalformedManifestIsFinding(): void
    {
        file_put_contents($this->root . '/ui/web/package.json', '{');
        $report = new Check($this->createStub(CommandExecutor::class))->run($this->root);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('Syntax error', $report->render());
    }

    public function testChangedAuthoredPatchIsFinding(): void
    {
        mkdir($this->root . '/ui/web/.yarn/patches', 0700, true);
        file_put_contents($this->root . '/ui/web/.yarn/patches/fix.patch', 'changed');
        $path = 'packages/baander-dev-dependencies/resources/javascript-dependencies.json';
        $policy = json_decode(file_get_contents($this->root . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        $policy['patches'] = [['project' => 'ui/web/package.json', 'path' => 'ui/web/.yarn/patches/fix.patch', 'sha256' => hash('sha256', 'original'), 'reason' => 'Fix behavior.']];
        $this->write($path, $policy);
        $report = new Check($this->createStub(CommandExecutor::class))->run($this->root);
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
        $report = new Check($executor)->run($this->root, false);
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
        $report = new Check($executor)->run($this->root, false);
        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('exited with status 1', $report->render());
        self::assertStringContainsString('7.0.2', $report->render());
    }

    public function testEmptyFailedAuditIsGap(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static fn(ProcessSpec $spec): ProcessResult => new ProcessResult(in_array('audit', $spec->argv, true) ? 1 : 0, null, false, false, '', '', 0.01));
        $report = new Check($executor)->run($this->root, false);
        self::assertStringContainsString('Audit failed without advisory evidence', $report->render());
        self::assertSame(2, $report->exitCode());
    }

    public function testAdvisoryCheckPassesADependencyThatTheOnlineAssessmentReportsAsOutdated(): void
    {
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use (&$commands): ProcessResult {
            $commands[] = $spec->argv;
            $output = match (true) {
                in_array('info', $spec->argv, true) => implode("\n", [
                    '{"value":"@baander/web@workspace:.","children":{"Version":"1.0.0","Dependencies":[{"descriptor":"typescript@npm:^6.0.3","locator":"typescript@npm:6.0.3"},{"descriptor":"failed@npm:^1.0.0","locator":"failed@npm:1.0.0"}]}}',
                    '{"value":"typescript@npm:6.0.3","children":{"Version":"6.0.3"}}',
                    '{"value":"failed@npm:1.0.0","children":{"Version":"1.0.0"}}',
                ]),
                in_array('audit', $spec->argv, true) => '',
                default => '"7.0.2"',
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        $check = new Check($executor);
        self::assertSame(1, $check->run($this->root, false)->exitCode());

        $commands = [];
        $report = $check->advisories($this->root);
        self::assertSame(0, $report->exitCode(), $report->render());
        self::assertSame('advisories-only', $report->toArray()['mode']);
        self::assertStringStartsWith('JavaScript dependencies: advisories-only; status 0', $report->render());
        self::assertSame([['yarn', 'npm', 'audit', '--all', '--recursive', '--json']], $commands);
        self::assertNotContains('freshness', array_column($report->toArray()['records'], 'kind'));
    }

    public function testAdvisoryCheckFailsOnAnUnlistedAdvisoryAndOnlyReportsADeprecation(): void
    {
        $report = new Check($this->auditExecutor(1, self::MINIMATCH_ADVISORY . "\n" . self::DEPRECATION))->advisories($this->root);
        self::assertSame(1, $report->exitCode(), $report->render());
        $records = $report->toArray()['records'];
        $finding = self::only($records, 'advisories');
        self::assertSame('finding', $finding['status']);
        self::assertSame(['GHSA-g3hv-75wg-m249'], array_column($finding['details']['advisories'], 'advisory'));
        self::assertSame(['minimatch'], array_column($finding['details']['advisories'], 'package'));
        $deprecations = self::only($records, 'deprecations');
        self::assertSame('evidence', $deprecations['status']);
        self::assertSame(['tldts-core'], array_column($deprecations['details']['deprecations'], 'package'));

        $deprecated = new Check($this->auditExecutor(1, self::DEPRECATION))->advisories($this->root);
        self::assertSame(0, $deprecated->exitCode(), $deprecated->render());
        self::assertStringContainsString('tldts-core', $deprecated->render());
    }

    public function testAdvisoryCheckHonoursOnlyAReasonedExceptionForTheSameProjectPackageAndAdvisory(): void
    {
        $exception = ['projects' => ['ui/web/package.json'], 'package' => 'minimatch', 'advisory' => 'GHSA-g3hv-75wg-m249', 'reason' => 'Reaches only the linter, which reads repository files.'];
        $check = new Check($this->auditExecutor(1, self::MINIMATCH_ADVISORY));

        $this->writeAdvisoryExceptions([$exception]);
        $report = $check->advisories($this->root);
        self::assertSame(0, $report->exitCode(), $report->render());
        $excepted = self::only($report->toArray()['records'], 'advisory-exception');
        self::assertSame('evidence', $excepted['status']);
        self::assertSame('Reaches only the linter, which reads repository files.', $excepted['reason']);
        self::assertSame('GHSA-g3hv-75wg-m249', $excepted['details']['advisory']);

        mkdir($this->root . '/ui/site');
        $this->write('ui/site/package.json', ['name' => '@baander/site']);
        $unlisted = [
            'another advisory' => [...$exception, 'advisory' => 'GHSA-3ppc-4f35-3m26'],
            'another package' => [...$exception, 'package' => 'brace-expansion'],
            'another project' => [...$exception, 'projects' => ['ui/site/package.json']],
        ];
        foreach ($unlisted as $case => $other) {
            $this->writeAdvisoryExceptions([$other], ['ui/site/package.json' => ['kind' => 'dependency-free', 'reason' => 'Fixture project without dependencies.']]);
            self::assertSame(1, $check->advisories($this->root)->exitCode(), $case);
        }

        $this->writeAdvisoryExceptions([$exception]);
        $second = str_replace(['GHSA-g3hv-75wg-m249', '1244623'], ['GHSA-3ppc-4f35-3m26', '1113465'], self::MINIMATCH_ADVISORY);
        $partial = new Check($this->auditExecutor(1, self::MINIMATCH_ADVISORY . "\n" . $second))->advisories($this->root);
        self::assertSame(1, $partial->exitCode(), $partial->render());
        self::assertSame(['GHSA-3ppc-4f35-3m26'], array_column(self::only($partial->toArray()['records'], 'advisories')['details']['advisories'], 'advisory'));
    }

    public function testMalformedAdvisoryExceptionFailsClosedBeforeAnyProcess(): void
    {
        $exception = ['projects' => ['ui/web/package.json'], 'package' => 'minimatch', 'advisory' => 'GHSA-g3hv-75wg-m249', 'reason' => 'Reaches only the linter, which reads repository files.'];
        $malformed = [
            'blank reason' => [...$exception, 'reason' => ' '],
            'no reason' => array_diff_key($exception, ['reason' => true]),
            'no advisory' => array_diff_key($exception, ['advisory' => true]),
            'no package' => array_diff_key($exception, ['package' => true]),
            'unclassified project' => [...$exception, 'projects' => ['ui/unclassified/package.json']],
        ];
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::never())->method('run');
        foreach ($malformed as $case => $entry) {
            $this->writeAdvisoryExceptions([$entry]);
            self::assertSame(2, new Check($executor)->advisories($this->root)->exitCode(), $case);
        }
        $this->writeAdvisoryExceptions(['minimatch' => $exception]);
        self::assertSame(2, new Check($executor)->advisories($this->root)->exitCode(), 'exceptions keyed by name');
        $policy = json_decode(file_get_contents($this->root . '/' . self::POLICY), true, flags: JSON_THROW_ON_ERROR);
        $this->write(self::POLICY, array_diff_key($policy, ['advisoryExceptions' => true]));
        self::assertSame(2, new Check($executor)->advisories($this->root)->exitCode(), 'no exception list');
    }

    public function testAdvisoriesOnlyFlagSelectsTheAdvisoryCheckAndItsExitStatus(): void
    {
        mkdir($this->root . '/bin');
        $stub = <<<'SH'
#!/bin/sh
if [ "$*" = 'npm audit --all --recursive --json' ]; then
    printf '%s\n' "$AUDIT_OUTPUT"
    exit 1
fi
exit 70
SH;
        file_put_contents($this->root . '/bin/yarn', $stub);
        chmod($this->root . '/bin/yarn', 0700);
        $code = 'require $argv[1]; exit(' . Command::class . '::main(array_slice($argv, 3), $argv[2], getenv()));';
        $environment = ['PATH' => $this->root . '/bin:' . (getenv('PATH') ?: ''), 'HOME' => $this->root, 'CI' => '1', 'AUDIT_OUTPUT' => self::MINIMATCH_ADVISORY];
        $executor = new EngineExecutor(ExecutionContext::bounded(30, $environment));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $code, dirname(__DIR__, 3) . '/scripts/bootstrap.php', $this->root, '--advisories-only'], $this->root), new CompletionPolicy(null));
        } finally {
            $executor->close();
        }
        self::assertSame(1, $result->exitCode, $result->stdout . $result->stderr);
        self::assertStringStartsWith('JavaScript dependencies: advisories-only; status 1', $result->stdout);
        self::assertStringContainsString('GHSA-g3hv-75wg-m249', $result->stdout);
    }

    public function testAdvisoryCheckAppliesTheSameRuleToAnNpmLock(): void
    {
        $this->useNpmLock();
        $audit = json_encode(['auditReportVersion' => 2, 'vulnerabilities' => [
            'minimatch' => ['name' => 'minimatch', 'severity' => 'moderate', 'isDirect' => false, 'via' => [['source' => 1244623, 'name' => 'minimatch', 'dependency' => 'minimatch', 'title' => 'minimatch has potential denial of service via globparts amplification', 'url' => 'https://github.com/advisories/GHSA-g3hv-75wg-m249', 'severity' => 'moderate', 'range' => '>=7.0.0 <11.0.1']], 'effects' => ['failed'], 'range' => '>=7.0.0 <11.0.1', 'nodes' => ['node_modules/minimatch'], 'fixAvailable' => true],
            'failed' => ['name' => 'failed', 'severity' => 'moderate', 'isDirect' => true, 'via' => ['minimatch'], 'effects' => [], 'range' => '*', 'nodes' => ['node_modules/failed'], 'fixAvailable' => true],
        ]], JSON_THROW_ON_ERROR);
        $check = new Check($this->auditExecutor(1, $audit));

        $report = $check->advisories($this->root);
        self::assertSame(1, $report->exitCode(), $report->render());
        $advisories = self::only($report->toArray()['records'], 'advisories')['details']['advisories'];
        self::assertSame([['package' => 'minimatch', 'advisory' => 'GHSA-g3hv-75wg-m249']], array_map(static fn(array $advisory): array => ['package' => $advisory['package'], 'advisory' => $advisory['advisory']], $advisories));

        $this->writeAdvisoryExceptions([['projects' => ['ui/web/package.json'], 'package' => 'minimatch', 'advisory' => 'GHSA-g3hv-75wg-m249', 'reason' => 'Reaches only the linter, which reads repository files.']]);
        self::assertSame(0, $check->advisories($this->root)->exitCode());

        $clean = new Check($this->auditExecutor(0, '{"auditReportVersion":2,"vulnerabilities":{}}'))->advisories($this->root);
        self::assertSame(0, $clean->exitCode(), $clean->render());
    }

    #[DataProvider('refusedAuditProvider')]
    public function testAdvisoryCheckIsIncompleteWhenTheAuditIsRefusedEmptyOrUnrecognized(string $manager, int $exit, string $stdout, string $stderr): void
    {
        if ($manager === 'npm') {
            $this->useNpmLock();
        }
        $report = new Check($this->auditExecutor($exit, $stdout, $stderr))->advisories($this->root);
        self::assertSame(2, $report->exitCode(), $report->render());
        $advisories = self::only($report->toArray()['records'], 'advisories');
        self::assertSame('gap', $advisories['status']);
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function refusedAuditProvider(): iterable
    {
        yield 'Yarn cannot be executed' => ['yarn', 126, '', 'sh: 1: yarn: Permission denied'];
        yield 'Yarn connection refused' => ['yarn', 1, '', 'RequestError: connect ECONNREFUSED 127.0.0.1:9'];
        yield 'Yarn fails with no output' => ['yarn', 1, '', ''];
        yield 'Yarn cannot read the lock' => ['yarn', 1, 'Internal Error: @baander/web@workspace:.: This package doesn\'t seem to be present in your lockfile; run "yarn install" to update the lockfile', ''];
        yield 'Yarn reports an error record' => ['yarn', 1, '{"type":"error","name":1,"displayName":"YN0001","indent":"","data":"RequestError: connect ECONNREFUSED 127.0.0.1:9"}', ''];
        yield 'Yarn advisory without an identifier' => ['yarn', 1, '{"value":"minimatch","children":{"Issue":"minimatch has potential denial of service via globparts amplification","Severity":"moderate"}}', ''];
        yield 'npm cannot be executed' => ['npm', 126, '', 'sh: 1: npm: Permission denied'];
        yield 'npm connection refused' => ['npm', 1, '{"message":"request to http://127.0.0.1:9/-/npm/v1/security/advisories/bulk failed, reason: connect ECONNREFUSED 127.0.0.1:9","error":{"summary":"","detail":""}}', 'npm error audit endpoint returned an error'];
        yield 'npm succeeds with no output' => ['npm', 0, '', ''];
        yield 'npm fails with no output' => ['npm', 1, '', ''];
        yield 'npm vulnerability without an advisory' => ['npm', 1, '{"auditReportVersion":2,"vulnerabilities":{"failed":{"name":"failed","severity":"high","via":["minimatch"]}}}', ''];
    }

    public function testAdvisoryCheckDoesNotPassAProjectWhoseLockIsMissing(): void
    {
        unlink($this->root . '/ui/web/yarn.lock');
        $report = new Check($this->auditExecutor(0, ''))->advisories($this->root);
        self::assertSame(1, $report->exitCode(), $report->render());
        self::assertStringContainsString('missing or empty', $report->render());
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
        $report = new Check($executor)->run($this->root, false);
        $records = array_filter($report->toArray()['records'], static fn(array $record): bool => $record['kind'] === 'freshness' && ($record['details']['package'] ?? null) === 'typescript') |> array_values(...);
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
        self::assertSame(0, new Check($executor)->run($this->root, false)->exitCode());
    }

    public function testNpmLockTreeAndAdvisoryEvidenceAreCollected(): void
    {
        $policyPath = 'packages/baander-dev-dependencies/resources/javascript-dependencies.json';
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
        $report = new Check($executor)->run($this->root, false);
        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('high', $report->render());
        self::assertStringContainsString('6.0.3', $report->render());
    }

    public function testIncompletePolicyFailsClosedBeforeAnyProcess(): void
    {
        $this->write('packages/baander-dev-dependencies/resources/javascript-dependencies.json', ['version' => 1]);
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::never())->method('run');
        $report = new Check($executor)->run($this->root, false);
        self::assertSame(2, $report->exitCode());
        self::assertSame('gap', $report->toArray()['records'][0]['status']);
    }

    public function testWrongPolicyShapesFailClosedBeforeAnyProcess(): void
    {
        $path = 'packages/baander-dev-dependencies/resources/javascript-dependencies.json';
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
            self::assertSame(2, new Check($executor)->run($this->root, false)->exitCode());
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
            $path = 'packages/baander-dev-dependencies/resources/javascript-dependencies.json';
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
        $report = new Check($executor)->run($this->root, false);
        self::assertSame(0, $report->exitCode());
        $records = $report->toArray()['records'];
        self::assertNotContains('gap', array_column($records, 'status'));
        $freshness = array_filter($records, static fn(array $record): bool => $record['kind'] === 'freshness') |> array_values(...);
        self::assertCount(1, $freshness);
        self::assertSame('evidence', $freshness[0]['status']);
        self::assertSame(['1.21.0'], $freshness[0]['details']['resolved']);
        self::assertSame('1.21.0', $freshness[0]['details']['latest']);
    }

    /** Answers the advisory audit with one result; any other command fails the test. */
    private function auditExecutor(int $exit, string $stdout, string $stderr = ''): CommandExecutor
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use ($exit, $stdout, $stderr): ProcessResult {
            self::assertContains('audit', $spec->argv);
            return new ProcessResult($exit, null, false, false, $stdout, $stderr, 0.01);
        });
        return $executor;
    }

    /**
     * @param array<array-key,array<string,mixed>> $exceptions
     * @param array<string,array<string,string>> $projects
     */
    private function writeAdvisoryExceptions(array $exceptions, array $projects = []): void
    {
        $policy = json_decode(file_get_contents($this->root . '/' . self::POLICY), true, flags: JSON_THROW_ON_ERROR);
        $this->write(self::POLICY, [...$policy, 'advisoryExceptions' => $exceptions, 'projects' => [...$policy['projects'], ...$projects]]);
    }

    private function useNpmLock(): void
    {
        $policy = json_decode(file_get_contents($this->root . '/' . self::POLICY), true, flags: JSON_THROW_ON_ERROR);
        $policy['projects']['ui/web/package.json'] = ['kind' => 'locked', 'manager' => 'npm', 'lock' => 'ui/web/package-lock.json'];
        $this->write(self::POLICY, $policy);
        $this->write('ui/web/package-lock.json', ['packages' => ['' => ['devDependencies' => ['typescript' => '^6.0.3', 'failed' => '^1.0.0']]]]);
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private static function only(array $records, string $kind): array
    {
        $matching = array_filter($records, static fn(array $record): bool => $record['kind'] === $kind) |> array_values(...);
        self::assertCount(1, $matching, $kind);
        return $matching[0];
    }

    /** @param array<array-key,mixed> $value */
    private function write(string $path, array $value): void
    {
        file_put_contents($this->root . '/' . $path, json_encode($value, JSON_THROW_ON_ERROR));
    }
}
