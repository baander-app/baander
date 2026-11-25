<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Tests;

use Baander\Configuration\ToolingConfiguration;
use Baander\DevDependencies\Php\Check;
use Baander\DevDependencies\Php\Command;
use Baander\DevDependencies\Php\ComposerEvidence;
use Baander\DevDependencies\Php\DockerEvidence;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\Process\Execution\CommandFailure;
use Baander\DevDependencies\Php\Report;
use Baander\DevDependencies\Php\UpstreamEvidence;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpDependencyCheckTest extends TestCase
{
    private const array LOCKED_PACKAGE = ['packages' => [['name' => 'public/package', 'version' => '1.0.0']], 'packages-dev' => []];
    private const string CLEAN_AUDIT = '{"advisories":[],"abandoned":[],"filter":[],"cooldown":[]}';

    public function testPackagistExpandsUnsetFieldsAndSortsStableIndependentlyOfPhpConstraint(): void
    {
        $releases = UpstreamEvidence::releases(['minified' => 'composer/2.0', 'packages' => ['public/package' => [
            ['name' => 'public/package', 'version' => '2.0.0', 'source' => ['reference' => 'new'], 'require' => ['php' => '^9']],
            ['version' => '1.0.0', 'source' => '__unset', 'require' => '__unset'],
            ['version' => '3.0.0-RC1'],
        ]]], 'public/package');
        self::assertSame(['2.0.0', '1.0.0'], array_column($releases, 'version'));
        self::assertSame('^9', $releases[0]['php']);
        self::assertNull($releases[1]['reference']);
        self::assertNull($releases[1]['php']);
    }

    public function testMalformedPackagistDoesNotMasqueradeAsNoUpdates(): void
    {
        $this->expectExceptionMessage('release list');
        UpstreamEvidence::releases(['packages' => []], 'public/package');
    }

    public function testRuntimeChannelsExcludePrereleases(): void
    {
        self::assertSame('8.5.12', UpstreamEvidence::runtimeVersion('php', ['preview' => ['version' => '8.6.0RC1'], 'stable' => ['version' => '8.5.12']]));
        self::assertSame('2.11.1', UpstreamEvidence::runtimeVersion('composer', ['stable' => [['version' => '2.11.1']], 'preview' => [['version' => '3.0.0']]]));
        self::assertSame('6.2.4', UpstreamEvidence::runtimeVersion('swoole', '<a><r><v>6.3.0RC1</v><s>beta</s></r><r><v>6.2.4</v><s>stable</s></r></a>'));
    }

    public function testHttpRateLimitProducesGapAndExistingFindingsSurvive(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturn(new ProcessResult(0, null, false, false, "{}\n429", '', 0.01));
        $report = new Report(false);
        $report->add('finding', 'composer.json', 'patch', 'patches/fix.patch', 'Hash mismatch.');
        try {
            (new UpstreamEvidence($executor, 2))->fetch('https://repo.packagist.org/p2/public/package.json');
            self::fail('Rate limit accepted.');
        } catch (\RuntimeException $error) {
            $report->add('gap', 'public/package', 'stable', 'packagist', $error->getMessage());
        }
        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('Hash mismatch', $report->render());
        self::assertStringContainsString('429', $report->render());
    }

    public function testOnlineCollectsBlockedStableAndRetainsFindingsAfterOtherSourceFailure(): void
    {
        $root = sys_get_temp_dir() . '/baander-dependency-check-' . bin2hex(random_bytes(8));
        mkdir($root . '/packages/baander-dev-dependencies/resources', 0700, true);
        file_put_contents($root . '/composer.json', json_encode(['require' => ['public/package' => '^1.0', 'public/failed' => '^1.0']], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/composer.lock', json_encode(['packages' => [['name' => 'public/package', 'version' => '1.0.0']], 'packages-dev' => []], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', json_encode(['version' => 1, 'projects' => ['composer.json' => ['kind' => 'locked', 'owner' => 'fixture', 'lock' => 'composer.lock']]], JSON_THROW_ON_ERROR));
        $calls = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use (&$calls): ProcessResult {
            $calls[] = $spec;
            $output = match (true) {
                in_array('--version', $spec->argv, true) => 'Composer version 2.11.1 2026-10-30 14:52:10',
                in_array('validate', $spec->argv, true) => './composer.json is valid',
                in_array('audit', $spec->argv, true) => '{"advisories":[],"abandoned":[],"filter":[],"cooldown":[]}',
                in_array('outdated', $spec->argv, true) => '{"locked":[]}',
                str_contains(implode(' ', $spec->argv), 'public/failed') => "{}\n500",
                default => '{"packages":{"public/package":[{"name":"public/package","version":"2.0.0","require":{"php":"^9"},"source":{"reference":"new"}}]}}' . "\n200",
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        try {
            $report = (new Check($executor, ToolingConfiguration::resolve()))->run($root);
            self::assertSame(2, $report->exitCode());
            $json = $report->render(true);
            self::assertStringContainsString('"constraintEligible": false', $json);
            self::assertStringContainsString('"latest"', $json);
            self::assertStringContainsString('500', $json);
            self::assertCount(6, $calls);
            $cancelledExecutor = $this->createMock(CommandExecutor::class);
            $cancelledExecutor->expects(self::once())->method('run')->willThrowException(new CommandFailure('Fixture cancellation.', 130, new ProcessResult(130, 2, false, true, '', '', 0.01)));
            $cancelled = (new Check($cancelledExecutor, ToolingConfiguration::resolve()))->run($root);
            self::assertSame(2, $cancelled->exitCode());
            self::assertStringContainsString('not performed after workflow cancellation', $cancelled->render());
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public function testUnsupportedComposerInterfaceIsIncomplete(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturn(new ProcessResult(0, null, false, false, 'Composer version 2.10.0 2026-10-30 14:52:10', '', 0.01));
        $this->expectExceptionMessage('2.11.1');
        (new ComposerEvidence($executor, 2, [], sys_get_temp_dir()))->version();
    }

    public function testFreshnessWarningRejectsSuccessfulComposerExit(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturn(new ProcessResult(0, null, false, false, '{"advisories":[],"abandoned":[],"filter":[],"cooldown":[]}', 'Using cached package information', 0.01));
        $this->expectExceptionMessage('stale');
        (new ComposerEvidence($executor, 2, [], sys_get_temp_dir()))->audit(sys_get_temp_dir());
    }

    public function testAuditRetainsAdvisoryAbandonmentAndIgnoredCategories(): void
    {
        $data = ['advisories' => ['public/package' => [['advisoryId' => 'PKSA-fixture', 'title' => 'Fixture advisory', 'affectedVersions' => '<2']]], 'abandoned' => ['public/abandoned' => null], 'ignored-advisories' => [], 'filter' => [], 'cooldown' => []];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturn(new ProcessResult(1, null, false, false, json_encode($data, JSON_THROW_ON_ERROR), '', 0.01));
        self::assertSame($data, (new ComposerEvidence($executor, 2, [], sys_get_temp_dir()))->audit(sys_get_temp_dir()));
    }

    public function testTimeoutRemainsAnExecutionFailure(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willThrowException(new CommandFailure('Fixture timeout.', 124, new ProcessResult(124, null, true, false, '', '', 0.01)));
        $this->expectException(CommandFailure::class);
        (new UpstreamEvidence($executor, 2))->fetch('https://repo.packagist.org/p2/public/package.json');
    }

    public function testDockerRegistryMetadataUsesClientWithoutPullingOrExecutingImages(): void
    {
        $reference = 'composer:2.11.1@sha256:' . str_repeat('a', 64);
        $metadata = ['linux/amd64' => ['config' => ['Env' => ['PHP_VERSION=8.5.12', 'COMPOSER_VERSION=2.11.1']]], 'linux/arm64/v8' => ['config' => ['Env' => ['PHP_VERSION=8.5.12', 'COMPOSER_VERSION=2.11.1']]]];
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::once())->method('run')->with(self::callback(static fn(ProcessSpec $spec): bool => $spec->argv === [PHP_BINARY, '--host', 'unix:///fixture.sock', 'buildx', 'imagetools', 'inspect', $reference, '--format', '{{json .Image}}'] && $spec->timeout === 2.0))->willReturn(new ProcessResult(0, null, false, false, json_encode($metadata, JSON_THROW_ON_ERROR), '', 0.01));
        $client = new Client($executor, new Target(PHP_BINARY, 'unix:///fixture.sock', 'fixture', null, null), []);
        $versions = (new DockerEvidence($client, 2))->composerImage($reference);
        self::assertCount(2, $versions);
        self::assertSame('8.5.12', $versions['linux/arm64/v8']['php']);
    }

    public function testSchemaAndLockFailuresRemainEvidenceWhileComposerCrashesAreIncomplete(): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnOnConsecutiveCalls(
            new ProcessResult(2, null, false, false, '', "./composer.json is valid but your composer.lock has some errors\n# Lock file errors\n- The lock file is not up to date with the latest changes in composer.json.", 0.01),
            new ProcessResult(2, null, false, false, '', "./composer.json is invalid, the following errors/warnings were found:\n# General errors\n- name : Invalid type for name.", 0.01),
            new ProcessResult(2, null, false, false, '', 'Fatal error: unexpected failure', 0.01),
        );
        $composer = new ComposerEvidence($executor, 2, [], sys_get_temp_dir());
        self::assertSame(2, $composer->validate(sys_get_temp_dir(), true)->exitCode);
        self::assertSame(2, $composer->validate(sys_get_temp_dir(), false)->exitCode);
        $this->expectExceptionMessage('interpretable');
        $composer->validate(sys_get_temp_dir(), true);
    }

    public function testLocalCleanCoverageExcludesOnlineChecks(): void
    {
        $report = new Report(true);
        self::assertSame(0, $report->exitCode());
        self::assertContains('online-currency', $report->toArray()['excluded']);
        self::assertNotContains('advisories', $report->toArray()['requested']);
    }
    public function testReportsShowActionableVersionsReferencesAndAdvisoriesWithCredentialsRedacted(): void
    {
        $report = new Report(false);
        $report->add('finding', 'composer.json', 'stable-releases', 'packagist', 'New stable release.', ['package' => 'public/package', 'constraint' => '^1.0', 'lockedVersion' => '1.0.0', 'lockedReference' => 'old-source-reference', 'latest' => ['version' => '2.0.0', 'reference' => 'new-source-reference']]);
        $report->add('finding', 'composer.json', 'advisories', 'composer', 'Advisory found.', ['advisories' => ['public/package' => [['advisoryId' => 'PKSA-fixture', 'title' => 'Fixture advisory', 'link' => 'https://user:private-password@baander.app/advisory?token=private-token']]], 'authorization' => 'private-authorization']);
        $text = $report->render() . $report->render(true);
        foreach (['^1.0', '1.0.0', '2.0.0', 'old-source-reference', 'new-source-reference', 'PKSA-fixture', '[redacted]'] as $detail) {
            self::assertStringContainsString($detail, $text);
        }
        $json = json_decode($report->render(true), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('new-source-reference', $json['records'][1]['details']['latest']['reference']);
        self::assertSame('2.0.0', $json['records'][1]['details']['latest']['version']);
        foreach (['private-password', 'private-token', 'private-authorization'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testHumanReportIdentifiesThePackageVersionsAndUpstreamReference(): void
    {
        $report = new Report(false);
        $report->add('finding', 'scripts/composer.json', 'stable-releases', 'packagist', 'New stable release.', [
            'package' => 'public/package',
            'lockedVersion' => '1.0.0',
            'latest' => ['version' => '2.0.0', 'reference' => 'upstream-source-reference'],
        ]);
        $text = $report->render();
        foreach (['scripts/composer.json', 'public/package', '1.0.0', '2.0.0', 'upstream-source-reference', 'packagist'] as $detail) {
            self::assertStringContainsString($detail, $text);
        }
    }

    public function testPeclAcceptsPublishedXmlDeclarationWhitespaceAndNamespaceAttributes(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<a xmlns="http://pear.php.net/dtd/rest.allreleases"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink"
    xsi:schemaLocation="http://pear.php.net/dtd/rest.allreleases
    http://pear.php.net/dtd/rest.allreleases.xsd">
 <p>swoole</p>
 <c>pecl.php.net</c>
 <r><v>6.3.0RC1</v><s>alpha</s></r>
 <r><v>6.2.4</v><s>stable</s></r>
 <r><v>6.2.3</v><s>stable</s></r>
</a>
XML;
        self::assertSame('6.2.4', UpstreamEvidence::runtimeVersion('swoole', $xml));
        $this->expectExceptionMessage('malformed PECL');
        UpstreamEvidence::runtimeVersion('swoole', str_replace('</a>', '<unexpected/></a>', $xml));
    }

    public function testKnownComposerEmptyProjectResponsesRequireAnActuallyEmptyLock(): void
    {
        $root = sys_get_temp_dir() . '/baander-empty-lock-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $diagnostic = 'No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.';
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static fn(ProcessSpec $spec): ProcessResult => in_array('audit', $spec->argv, true)
            ? new ProcessResult(1, null, false, false, '', $diagnostic . "\n", 0.01)
            : new ProcessResult(0, null, false, false, "[]\n", '', 0.01));
        $composer = new ComposerEvidence($executor, 2, [], sys_get_temp_dir());
        try {
            file_put_contents($root . '/composer.lock', '{"packages":[],"packages-dev":[]}');
            self::assertSame('empty-lock', $composer->audit($root)['scope']);
            self::assertSame(['locked' => [], 'scope' => 'empty-lock'], $composer->outdated($root));
            foreach (['{"packages":[{"name":"public/package","version":"1.0.0"}],"packages-dev":[]}', '{"packages":{},"packages-dev":[]}', '{"packages":[]}'] as $lock) {
                file_put_contents($root . '/composer.lock', $lock);
                foreach (['audit', 'outdated'] as $method) {
                    try {
                        $composer->$method($root);
                        self::fail('Empty Composer output was accepted for a nonempty or malformed owned lock.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('empty owned lock', $error->getMessage());
                    }
                }
            }
        } finally {
            unlink($root . '/composer.lock');
            rmdir($root);
        }
    }

    public function testUnknownComposerFailuresDoNotBecomeEmptyLockEvidence(): void
    {
        $root = sys_get_temp_dir() . '/baander-empty-lock-failure-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root . '/composer.lock', '{"packages":[],"packages-dev":[]}');
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnOnConsecutiveCalls(
            new ProcessResult(1, null, false, false, '', 'Fixture advisory service unavailable.', 0.01),
            new ProcessResult(1, null, false, false, '[]', '', 0.01),
        );
        $composer = new ComposerEvidence($executor, 2, [], sys_get_temp_dir());
        try {
            foreach (['audit', 'outdated'] as $method) {
                try {
                    $composer->$method($root);
                    self::fail('Unknown failure was accepted as an empty-project response.');
                } catch (\RuntimeException|\JsonException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            }
        } finally {
            unlink($root . '/composer.lock');
            rmdir($root);
        }
    }

    public function testVerifiedEmptyLockOnlineAssessmentHasExplicitScopeAndNoFalseFinding(): void
    {
        $root = sys_get_temp_dir() . '/baander-empty-assessment-' . bin2hex(random_bytes(8));
        mkdir($root . '/packages/baander-dev-dependencies/resources', 0700, true);
        file_put_contents($root . '/composer.json', '{"name":"baander/fixture","require":{"php":">=8.5"}}');
        file_put_contents($root . '/composer.lock', '{"packages":[],"packages-dev":[]}');
        file_put_contents($root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', '{"version":1,"projects":{"composer.json":{"kind":"locked","owner":"fixture","lock":"composer.lock"}}}');
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec): ProcessResult {
            [$exit, $stdout, $stderr] = match (true) {
                in_array('--version', $spec->argv, true) => [0, 'Composer version 2.11.1 2026-10-30 14:52:10', ''],
                in_array('validate', $spec->argv, true) => [0, './composer.json is valid', ''],
                in_array('audit', $spec->argv, true) => [1, '', 'No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.'],
                default => [0, '[]', ''],
            };
            return new ProcessResult($exit, null, false, false, $stdout, $stderr, 0.01);
        });
        try {
            $report = (new Check($executor, ToolingConfiguration::resolve()))->run($root);
            self::assertSame(0, $report->exitCode(), $report->render());
            self::assertStringContainsString('empty-lock', $report->render(true));
            self::assertStringContainsString('no auditable packages', $report->render());
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public function testOutdatedRetainsPlatformDiagnosticsAlongsideTheRawLowerCandidate(): void
    {
        $locked = [['name' => 'lcobucci/jwt', 'version' => '5.6.1', 'latest' => '4.0.4', 'latest-status' => 'update-possible']];
        $diagnostic = "Cannot use lcobucci/jwt's latest version 5.6.1 as it requires ext-sodium * which is missing from your platform.\n";
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturn(new ProcessResult(0, null, false, false, json_encode(['locked' => $locked], JSON_THROW_ON_ERROR), $diagnostic, 0.01));
        $data = (new ComposerEvidence($executor, 2, [], sys_get_temp_dir()))->outdated(sys_get_temp_dir());
        self::assertSame($locked, $data['locked']);
        self::assertSame($diagnostic, $data['diagnostics']);
        $report = new Report(false);
        $report->add('finding', 'composer.json', 'outdated', 'composer', 'Host-selected Composer candidates.', $data);
        self::assertSame(1, $report->exitCode());
        foreach (['5.6.1', '4.0.4', 'ext-sodium', 'missing from your platform'] as $detail) {
            self::assertStringContainsString($detail, $report->render());
        }
    }

    public function testOutdatedNetworkAndStaleDiagnosticsRemainIncompleteEvidence(): void
    {
        foreach (['Using cached package information', 'Could not download https://repo.packagist.org/p2/lcobucci/jwt.json'] as $diagnostic) {
            $executor = $this->createStub(CommandExecutor::class);
            $executor->method('run')->willReturn(new ProcessResult(0, null, false, false, '{"locked":[]}', $diagnostic, 0.01));
            try {
                (new ComposerEvidence($executor, 2, [], sys_get_temp_dir()))->outdated(sys_get_temp_dir());
                self::fail('Unavailable or stale evidence was accepted as a platform diagnostic.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('unavailable or stale', $error->getMessage());
            }
        }
    }

    public function testIndependentStableChecksIncludeTransitiveLocksDespiteCleanComposerOutdated(): void
    {
        $root = sys_get_temp_dir() . '/baander-transitive-stable-' . bin2hex(random_bytes(8));
        mkdir($root . '/packages/baander-dev-dependencies/resources', 0700, true);
        mkdir($root . '/scripts');
        $manifest = ['name' => 'baander/fixture', 'require' => ['php' => '>=8.5', 'public/direct' => '^1.0']];
        $lock = ['packages' => [['name' => 'public/direct', 'version' => '1.0.0'], ['name' => 'public/transitive', 'version' => '1.0.0', 'source' => ['reference' => 'locked-transitive-reference']], ['name' => 'baander/internal', 'version' => '1.0.0']], 'packages-dev' => [['name' => 'public/dev-transitive', 'version' => '1.0.0']]];
        foreach (['', 'scripts/'] as $directory) {
            file_put_contents($root . '/' . $directory . 'composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            file_put_contents($root . '/' . $directory . 'composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        }
        $policy = ['version' => 1, 'internalPackages' => ['baander/internal'], 'projects' => ['composer.json' => ['kind' => 'locked', 'owner' => 'fixture', 'lock' => 'composer.lock'], 'scripts/composer.json' => ['kind' => 'locked', 'owner' => 'fixture', 'lock' => 'scripts/composer.lock']]];
        file_put_contents($root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', json_encode($policy, JSON_THROW_ON_ERROR));
        $requestedPackages = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use (&$requestedPackages): ProcessResult {
            if ($spec->argv[0] === 'curl') {
                $url = $spec->argv[array_key_last($spec->argv)];
                $path = parse_url($url, PHP_URL_PATH);
                $name = substr($path, strlen('/p2/'), -strlen('.json'));
                $requestedPackages[] = $name;
                $release = ['name' => $name, 'version' => $name === 'public/transitive' ? '2.0.0' : '1.0.0', 'require' => ['php' => '^9'], 'source' => ['reference' => 'latest-source-reference']];
                $stdout = json_encode(['packages' => [$name => [$release]]], JSON_THROW_ON_ERROR) . "\n200";
            } else {
                $stdout = match (true) {
                    in_array('--version', $spec->argv, true) => 'Composer version 2.11.1 2026-10-30 14:52:10',
                    in_array('validate', $spec->argv, true) => './composer.json is valid',
                    in_array('audit', $spec->argv, true) => '{"advisories":[],"abandoned":[],"filter":[],"cooldown":[]}',
                    default => '{"locked":[]}',
                };
            }
            return new ProcessResult(0, null, false, false, $stdout, '', 0.01);
        });
        try {
            $report = (new Check($executor, ToolingConfiguration::resolve()))->run($root);
            self::assertSame(1, $report->exitCode(), $report->render());
            sort($requestedPackages);
            self::assertSame(['public/dev-transitive', 'public/direct', 'public/transitive'], $requestedPackages);
            $stable = array_values(array_filter($report->toArray()['records'], static fn(array $record): bool => $record['kind'] === 'stable-releases'));
            self::assertCount(6, $stable);
            foreach ($stable as $record) {
                $details = $record['details'];
                if ($details['package'] === 'public/direct') {
                    self::assertSame('direct', $details['ownership']);
                    self::assertSame('^1.0', $details['constraint']);
                    self::assertTrue($details['constraintEligible']);
                } else {
                    self::assertSame('locked-transitive', $details['ownership']);
                    self::assertNull($details['constraint']);
                    self::assertNull($details['constraintEligible']);
                    if ($details['package'] === 'public/transitive') {
                        self::assertSame('finding', $record['status']);
                        self::assertSame('2.0.0', $details['latest']['version']);
                        self::assertSame('^9', $details['latest']['php']);
                        self::assertSame('locked-transitive-reference', $details['lockedReference']);
                    } else {
                        self::assertSame('packages-dev', $details['lockedSection']);
                    }
                }
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public function testAdvisoryCheckPassesAnOutdatedLockThatTheOnlineAssessmentReports(): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use (&$commands): ProcessResult {
            $commands[] = array_filter($spec->argv, static fn(string $argument): bool => !str_starts_with($argument, '--no-') && !str_starts_with($argument, '--working-dir=')) |> array_values(...);
            $output = match (true) {
                in_array('--version', $spec->argv, true) => 'Composer version 2.11.1 2026-10-30 14:52:10',
                in_array('validate', $spec->argv, true) => './composer.json is valid',
                in_array('audit', $spec->argv, true) => self::CLEAN_AUDIT,
                in_array('outdated', $spec->argv, true) => '{"locked":[{"name":"public/package","version":"1.0.0","latest":"1.1.0","latest-status":"semver-safe-update"}]}',
                default => '{"packages":{"public/package":[{"name":"public/package","version":"1.1.0","source":{"reference":"new"}}]}}' . "\n200",
            };
            return new ProcessResult(0, null, false, false, $output, '', 0.01);
        });
        try {
            $check = new Check($executor, ToolingConfiguration::resolve());
            self::assertSame(1, $check->run($root)->exitCode());

            $commands = [];
            $report = $check->advisories($root);
            self::assertSame(0, $report->exitCode(), $report->render());
            self::assertSame([['composer', '--version'], ['composer', 'audit', '--locked', '--format=json']], $commands);
            $data = $report->toArray();
            self::assertSame('advisories-only', $data['mode']);
            self::assertSame(['inventory', 'locks', 'advisories'], $data['requested']);
            self::assertContains('online-currency', $data['excluded']);
            self::assertStringStartsWith('PHP dependencies: advisories-only; status 0', $report->render());
        } finally {
            self::remove($root);
        }
    }

    /** @param array<string,mixed> $categories */
    #[DataProvider('auditVerdictProvider')]
    public function testAdvisoryCheckFailsOnAdvisoriesAndPolicyListMatchesAndOnlyReportsTheRest(int $composerExit, array $categories, int $expected, string $reported): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        $audit = json_encode([...json_decode(self::CLEAN_AUDIT, true, flags: JSON_THROW_ON_ERROR), ...$categories], JSON_THROW_ON_ERROR);
        try {
            $report = (new Check($this->auditExecutor(new ProcessResult($composerExit, null, false, false, $audit, '', 0.01)), ToolingConfiguration::resolve()))->advisories($root);
            self::assertSame($expected, $report->exitCode(), $report->render());
            self::assertStringContainsString($reported, $report->render());
        } finally {
            self::remove($root);
        }
    }

    /** @return iterable<string, array{int, array<string,mixed>, int, string}> */
    public static function auditVerdictProvider(): iterable
    {
        $advisory = ['public/package' => [['advisoryId' => 'PKSA-fixture', 'title' => 'Fixture advisory', 'affectedVersions' => '<2']]];
        yield 'advisory' => [1, ['advisories' => $advisory], 1, 'PKSA-fixture'];
        yield 'malware list match' => [1, ['filter' => ['public/package' => [['packageName' => 'public/package', 'listName' => 'malware', 'constraint' => '1.0.0', 'url' => null, 'reason' => 'Fixture malware report', 'id' => 'MAL-fixture', 'source' => null]]]], 1, 'MAL-fixture'];
        yield 'abandoned package' => [1, ['abandoned' => ['public/package' => 'public/replacement']], 0, 'public/replacement'];
        yield 'abandoned package beside an advisory' => [1, ['advisories' => $advisory, 'abandoned' => ['public/package' => null]], 1, 'abandoned'];
        yield 'advisory the project ignores' => [0, ['ignored-advisories' => $advisory], 0, 'PKSA-fixture'];
        yield 'release inside the cooldown period' => [1, ['cooldown' => ['public/package' => ['prettyVersion' => '1.0.0', 'releaseDate' => '2026-11-23T08:00:00+00:00', 'availableIn' => '2 days', 'source' => 'published-time']]], 0, 'availableIn'];
    }

    #[DataProvider('refusedAuditProvider')]
    public function testAdvisoryCheckIsIncompleteWhenTheAuditIsRefusedEmptyOrUnreachable(ProcessResult|CommandFailure $audit): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        try {
            $report = (new Check($this->auditExecutor($audit), ToolingConfiguration::resolve()))->advisories($root);
            self::assertSame(2, $report->exitCode(), $report->render());
            $advisories = array_filter($report->toArray()['records'], static fn(array $record): bool => $record['kind'] === 'advisories');
            self::assertContains('gap', array_column($advisories, 'status'));
            self::assertNotContains('evidence', array_column($advisories, 'status'));
        } finally {
            self::remove($root);
        }
    }

    /** @return iterable<string, array{ProcessResult|CommandFailure}> */
    public static function refusedAuditProvider(): iterable
    {
        $result = static fn(int $exit, string $stdout, string $stderr = ''): ProcessResult => new ProcessResult($exit, null, false, false, $stdout, $stderr, 0.01);
        yield 'Composer cannot be executed' => [$result(126, '', 'sh: 1: composer: Permission denied')];
        yield 'name resolution refused' => [$result(100, '', "In CurlDownloader.php line 498:\n\n  curl error 6 while downloading https://repo.packagist.org/packages.json: Co  \n  uld not resolve host: repo.packagist.org  \n")];
        yield 'connection refused' => [$result(100, '', 'curl error 7 while downloading https://repo.packagist.org/packages.json: Failed to connect to repo.packagist.org port 443 after 2 ms: Could not connect to server')];
        yield 'successful exit with no output' => [$result(0, '')];
        yield 'failed exit with no output' => [$result(1, '')];
        yield 'failed exit with a clean report' => [$result(1, self::CLEAN_AUDIT)];
        yield 'empty-lock refusal for a lock that holds packages' => [$result(1, '', 'No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.')];
        yield 'unreachable repository' => [$result(0, '{"advisories":[],"unreachable-repositories":["https://repo.packagist.org"],"abandoned":[],"filter":[],"cooldown":[]}')];
        yield 'timeout' => [new CommandFailure('Fixture timeout.', 124, new ProcessResult(124, null, true, false, '', '', 0.01))];
    }

    public function testAdvisoryCheckReportsAnEmptyOwnedLockAsItsOwnScope(): void
    {
        $root = self::lockedProject(['packages' => [], 'packages-dev' => []]);
        $refusal = new ProcessResult(1, null, false, false, '', 'No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.', 0.01);
        try {
            $report = (new Check($this->auditExecutor($refusal), ToolingConfiguration::resolve()))->advisories($root);
            self::assertSame(0, $report->exitCode(), $report->render());
            $advisories = array_filter($report->toArray()['records'], static fn(array $record): bool => $record['kind'] === 'advisories') |> array_values(...);
            self::assertCount(1, $advisories);
            self::assertSame('evidence', $advisories[0]['status']);
            self::assertSame('empty-lock', $advisories[0]['details']['scope']);
            self::assertStringContainsString('no auditable packages', $advisories[0]['reason']);
        } finally {
            self::remove($root);
        }
    }

    public function testAdvisoryCheckReportsAClassifiedProjectItCannotReadInsteadOfSkippingIt(): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        file_put_contents($root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', '{"version":1,"projects":{"composer.json":{"kind":"locked","owner":"fixture","lock":"composer.lock"},"scripts/composer.json":{"kind":"locked","owner":"fixture","lock":"scripts/composer.lock"}}}');
        try {
            $report = (new Check($this->auditExecutor(new ProcessResult(0, null, false, false, self::CLEAN_AUDIT, '', 0.01)), ToolingConfiguration::resolve()))->advisories($root);
            self::assertSame(2, $report->exitCode(), $report->render());
            self::assertStringContainsString('scripts/composer.json', $report->render());
        } finally {
            self::remove($root);
        }
    }

    public function testAdvisoryCheckIsIncompleteWithoutTheSupportedComposerInterface(): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        $executor = $this->createMock(CommandExecutor::class);
        $executor->expects(self::once())->method('run')->willReturn(new ProcessResult(0, null, false, false, 'Composer version 2.10.0 2026-10-30 14:52:10', '', 0.01));
        try {
            $report = (new Check($executor, ToolingConfiguration::resolve()))->advisories($root);
            self::assertSame(2, $report->exitCode(), $report->render());
            self::assertStringContainsString('2.11.1', $report->render());
        } finally {
            self::remove($root);
        }
    }

    public function testAdvisoriesOnlyFlagSelectsTheAdvisoryCheckAndItsExitStatus(): void
    {
        $root = self::lockedProject(self::LOCKED_PACKAGE);
        mkdir($root . '/bin');
        $stub = <<<'PHP_STUB'
<?php
if (in_array('--version', $argv, true)) {
    echo 'Composer version 2.11.1 2026-10-30 14:52:10';
} elseif (array_slice($argv, -3) === ['audit', '--locked', '--format=json']) {
    echo '{"advisories":{"public/package":[{"advisoryId":"PKSA-fixture","title":"Fixture advisory","affectedVersions":"<2"}]},"abandoned":[],"filter":[],"cooldown":[]}';
    exit(1);
} else {
    exit(70);
}
PHP_STUB;
        file_put_contents($root . '/bin/composer', '#!' . PHP_BINARY . "\n" . $stub);
        chmod($root . '/bin/composer', 0700);
        $code = 'require $argv[1]; exit(' . Command::class . '::main(array_slice($argv, 3), $argv[2], getenv()));';
        $environment = ['PATH' => $root . '/bin:' . (getenv('PATH') ?: ''), 'HOME' => $root, 'CI' => '1'];
        $executor = new EngineExecutor(ExecutionContext::bounded(30, $environment));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', $code, dirname(__DIR__, 3) . '/scripts/bootstrap.php', $root, '--advisories-only'], $root), new CompletionPolicy(null));
            self::assertSame(1, $result->exitCode, $result->stdout . $result->stderr);
            self::assertStringStartsWith('PHP dependencies: advisories-only; status 1', $result->stdout);
            self::assertStringContainsString('PKSA-fixture', $result->stdout);
        } finally {
            $executor->close();
            self::remove($root);
        }
    }

    /** Answers the interface probe and gives the locked audit one outcome; any other command fails the test. */
    private function auditExecutor(ProcessResult|CommandFailure $audit): CommandExecutor
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $spec) use ($audit): ProcessResult {
            if (in_array('--version', $spec->argv, true)) {
                return new ProcessResult(0, null, false, false, 'Composer version 2.11.1 2026-10-30 14:52:10', '', 0.01);
            }
            self::assertSame(['audit', '--locked', '--format=json'], array_slice($spec->argv, -3));
            return $audit instanceof CommandFailure ? throw $audit : $audit;
        });
        return $executor;
    }

    /** @param array{packages: list<array<string,string>>, packages-dev: list<array<string,string>>} $lock */
    private static function lockedProject(array $lock): string
    {
        $root = sys_get_temp_dir() . '/baander-advisory-check-' . bin2hex(random_bytes(8));
        mkdir($root . '/packages/baander-dev-dependencies/resources', 0700, true);
        file_put_contents($root . '/composer.json', '{"name":"baander/fixture","require":{"php":">=8.5","public/package":"^1.0"}}');
        file_put_contents($root . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/packages/baander-dev-dependencies/resources/php-dependencies.json', '{"version":1,"projects":{"composer.json":{"kind":"locked","owner":"fixture","lock":"composer.lock"}}}');
        return $root;
    }

    private static function remove(string $root): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
