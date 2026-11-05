<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Local contracts for the workflow tree that Forgejo and GitHub both run, with real shell checks.
 *
 * Expressions are evaluated by a small evaluator for the subset the workflows use, once with the
 * GitHub server URL and once with a Forgejo one. This does not run either forge's scheduler.
 */
final class PublicationWorkflowTest extends TestCase
{
    private const string FORGE = "github.server_url == 'https://github.com'";
    private const string TRUSTED = "github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository";
    private const string CANCEL_PULL_REQUESTS = "\${{ github.event_name == 'pull_request' }}";
    private const string GITHUB = 'https://github.com';
    private const string FORGEJO = 'https://forge.baander.app';
    private const string REPOSITORY = 'baander-app/baander';
    private const string FORK = 'contributor/baander';
    private const string UPLOAD_ARTIFACT = 'actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9';
    private const string FORGEJO_UPLOAD_ARTIFACT = './.github/actions/forgejo-upload-artifact';
    private const array GATE_WORKFLOWS = ['ci.yaml', 'frontend.yaml', 'website.yaml', 'dsp-analysis.yaml', 'registry-nightly.yaml'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-publication-' . bin2hex(random_bytes(8));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testApplicationPublicationRequiresAllQualificationJobs(): void
    {
        $jobs = $this->workflow('ci.yaml')['jobs'];
        $publisher = $jobs['build-and-push'];
        self::assertSame([
            'quality-gate',
            'frontend-quality',
            'dsp-quality',
            'registry-quality',
            'registry-container-quality',
            'registry-thread-quality',
            'registry-fuzz-quality',
            'registry-static-quality',
        ], $publisher['needs']);
        self::assertSame(['release', 'sanitize'], $jobs['registry-quality']['strategy']['matrix']['mode']);
        self::assertArrayNotHasKey('continue-on-error', $publisher);
        self::assertArrayNotHasKey('if', $publisher);

        foreach (self::qualificationCommands() as [$job, $stepName, $command]) {
            $step = $this->step($jobs[$job], $stepName);
            self::assertStringContainsString($command, $step['run']);
            self::assertArrayNotHasKey('continue-on-error', $step);
            self::assertArrayNotHasKey('if', $step);
            self::assertArrayNotHasKey('continue-on-error', $jobs[$job]);
        }

        foreach ($jobs as $jobName => $job) {
            foreach ($job['steps'] as $step) {
                if (preg_match_all('~--revision\s+("[^"]*"|\S+)~', $step['run'] ?? '', $revisions) > 0) {
                    // A pull request image carries the head commit it qualified, not the merge commit.
                    self::assertSame(
                        array_fill(0, count($revisions[1]), '"${{ github.event.pull_request.head.sha || github.sha }}"'),
                        $revisions[1],
                        $jobName . ' ' . $step['name'],
                    );
                }
            }
        }

        $build = $this->step($publisher, 'Build production image');
        self::assertStringContainsString('php scripts/build-application.php --buildx', $build['run']);
        self::assertStringContainsString('--target production', $build['run']);
        self::assertArrayNotHasKey('if', $build);

        $inventory = $this->step($publisher, 'Collect and verify production packaged inventory');
        self::assertStringContainsString('scripts/collect-packaged-inventory.sh', $inventory['run']);
        self::assertArrayNotHasKey('if', $inventory);
        self::assertArrayNotHasKey('continue-on-error', $inventory);

        $publication = $this->step($publisher, 'Push verified production image');
        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            self::assertTrue(self::runs($publication, self::context($server)), $server);
            self::assertFalse(self::runs($publication, self::context($server, 'pull_request')), $server);
            self::assertFalse(self::runs($publication, self::context($server, 'pull_request', self::FORK)), $server);
        }
        self::assertStringContainsString('docker push "$REGISTRY/$IMAGE_NAME:${{ github.sha }}"', $publication['run']);
        self::assertStringContainsString('docker push "$REGISTRY/$IMAGE_NAME:latest"', $publication['run']);
        $names = array_column($publisher['steps'], 'name');
        self::assertLessThan(array_search($inventory['name'], $names, true), array_search($build['name'], $names, true));
        self::assertLessThan(array_search($publication['name'], $names, true), array_search($inventory['name'], $names, true));
    }

    public function testDspGateUsesTheExistingPinnedQualificationRecipe(): void
    {
        $application = $this->workflow('ci.yaml')['jobs']['dsp-quality'];
        $standalone = $this->workflow('dsp-analysis.yaml')['jobs']['qualify'];

        foreach (['Install pinned Emscripten', 'Build and qualify analysis modules'] as $name) {
            self::assertSame($this->step($standalone, $name)['run'], $this->step($application, $name)['run']);
        }
    }

    public function testRegistryQualificationHasDedicatedBoundedStorage(): void
    {
        $job = $this->workflow('ci.yaml')['jobs']['registry-quality'];
        self::assertSame(['release', 'sanitize'], $job['strategy']['matrix']['mode']);
        self::assertSame('/run/baander-registry-full-disk', $job['env']['REGISTRY_FULL_DISK_ROOT'] ?? null);
        self::assertSame(
            '--tmpfs /run/baander-registry-full-disk:rw,noexec,nosuid,nodev,size=128m,mode=0700',
            $job['container']['options'] ?? null,
        );
    }

    public function testNightlyRegistryRunsFiveVoterFuzzAndRetainsDiagnostics(): void
    {
        $workflow = $this->workflow('registry-nightly.yaml');
        self::assertSame(['schedule', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame([['cron' => '17 2 * * *']], $workflow['on']['schedule']);
        $job = $workflow['jobs']['qualify'];
        $existing = $this->workflow('ci.yaml')['jobs']['registry-fuzz-quality'];
        self::assertSame($existing['runs-on'], $job['runs-on']);
        self::assertSame($existing['container']['image'], $job['container']['image']);
        self::assertSame('--init', $job['container']['options']);
        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            self::assertSame(45, self::timeout($job, self::context($server)), $server);
        }
        self::assertSame(
            $this->step($existing, 'Install parser fuzz toolchain')['run'],
            $this->step($job, 'Install parser fuzz toolchain')['run'],
        );
        self::assertSame(
            $this->step($existing, 'Prepare standalone PHP tooling')['run'],
            $this->step($job, 'Prepare standalone PHP tooling')['run'],
        );
        $run = $this->step($job, 'Qualify five-voter Registry');
        self::assertSame('REGISTRY_SUBJECT_PYTHON=/usr/bin/python3 bash scripts/test-registry.sh fuzz', $run['run']);
        self::assertSame('5', $run['env']['REGISTRY_CLUSTER_NODES']);
        self::assertSame('10000', $run['env']['REGISTRY_FUZZ_RUNS']);
        self::assertSame('120', $run['env']['REGISTRY_FUZZ_SECONDS']);
        self::assertSame($this->step($existing, 'Fuzz registry parser and registration validation')['env']['CXX'], $run['env']['CXX']);
        self::assertArrayNotHasKey('continue-on-error', $run);
        self::assertArrayNotHasKey('continue-on-error', $job);
        self::assertArrayNotHasKey('if', $run);
        $output = 'var/test-runs/registry-nightly/${{ github.run_id }}-${{ github.run_attempt }}';
        self::assertSame($output, $run['env']['REGISTRY_QUALIFICATION_OUTPUT']);
        $existingUpload = $this->step($this->workflow('ci.yaml')['jobs']['registry-container-quality'], 'Preserve packaged Registry inventories on GitHub');
        foreach (['GitHub', 'Forgejo'] as $forge) {
            $upload = $this->step($job, 'Preserve nightly qualification evidence on ' . $forge);
            self::assertSame($this->step($this->workflow('ci.yaml')['jobs']['registry-container-quality'], 'Preserve packaged Registry inventories on ' . $forge)['uses'], $upload['uses']);
            self::assertStringStartsWith('always() && ', $upload['if']);
            self::assertSame($output, $upload['with']['path']);
            self::assertSame('registry-nightly-${{ github.run_id }}-${{ github.run_attempt }}', $upload['with']['name']);
            self::assertSame(14, $upload['with']['retention-days']);
            self::assertSame('error', $upload['with']['if-no-files-found']);
        }
        self::assertSame(self::UPLOAD_ARTIFACT, $existingUpload['uses']);
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryQualificationRejectsMissingStorageBeforeDownloading(string $mode): void
    {
        $process = $this->registryRecipe($mode, false);
        $process->run();

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('REGISTRY_FULL_DISK_ROOT', $process->getErrorOutput());
        self::assertStringNotContainsString('curl ', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.php', $this->registryCalls());
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryQualificationRejectsUnsafeStorageBeforeDownloading(string $mode): void
    {
        $process = $this->registryRecipe($mode, true, 43);
        $process->run();

        self::assertSame(43, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('validateRoot', $this->registryCalls());
        self::assertStringNotContainsString('curl ', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.php', $this->registryCalls());
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryStorageFailureStopsTheActualQualificationRecipe(string $mode): void
    {
        $process = $this->registryRecipe($mode, true);
        $process->run();

        self::assertSame(47, $process->getExitCode(), $process->getErrorOutput());
        $calls = $this->registryCalls();
        self::assertStringContainsString('validateRoot', $calls);
        self::assertStringContainsString('test_storage_cleanup.php', $calls);
        self::assertStringContainsString('run_storage_contract.php', $calls);
        self::assertStringContainsString('--storage-root /run/baander-registry-full-disk', $calls);
        self::assertStringContainsString('--rqlited ', $calls);
        self::assertStringContainsString('--server ', $calls);
        self::assertStringContainsString('run_restore_contract.php', $calls);
        self::assertLessThan(strpos($calls, 'curl '), strpos($calls, 'validateRoot'));
        self::assertLessThan(strpos($calls, 'run_storage_contract.php'), strpos($calls, 'run_restore_contract.php'));
        self::assertStringNotContainsString('run_load_smoke.php', $calls);
        self::assertStringNotContainsString('qualification passed', $process->getOutput());
    }

    #[DataProvider('registryModeProvider')]
    public function testRegistryStorageHelperFailureStopsEveryQualificationMode(string $mode): void
    {
        $process = $this->registryRecipe($mode, in_array($mode, ['release', 'sanitize'], true), storageHelperExit: 49);
        $process->run();

        self::assertSame(49, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('test_storage_cleanup.php', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.php', $this->registryCalls());
        self::assertStringNotContainsString('qualification passed', $process->getOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function requiredRegistryModeProvider(): iterable
    {
        yield 'release' => ['release'];
        yield 'sanitize' => ['sanitize'];
    }

    /** @return iterable<string, array{string}> */
    public static function registryModeProvider(): iterable
    {
        yield from self::requiredRegistryModeProvider();
        yield 'thread' => ['thread'];
        yield 'fuzz' => ['fuzz'];
    }

    #[DataProvider('failedQualificationProvider')]
    public function testFailedQualificationShellPropagatesFailureThroughRequiredDependency(
        string $server,
        string $job,
        string $stepName,
        string $command,
        string $matrixMode = 'release',
    ): void {
        $jobs = $this->workflow('ci.yaml')['jobs'];
        $step = $this->step($jobs[$job], $stepName);
        $tools = $this->directory . '/tools';
        new Filesystem()->mkdir([$tools, $this->directory . '/scripts', $this->directory . '/sdk']);
        file_put_contents($this->directory . '/sdk/emsdk_env.sh', '');
        $log = $this->directory . '/calls';
        foreach (['yarn', 'corepack', 'docker', 'node', 'bash', 'php'] as $tool) {
            $script = <<<'SH'
#!/bin/sh
printf '%s %s\n' "$(basename "$0")" "$*" >> "$CALL_LOG"
case "$(basename "$0") $*" in
  *"$FAIL_COMMAND"*) exit 47 ;;
esac
exit 0
SH;
            file_put_contents($tools . '/' . $tool, $script);
            chmod($tools . '/' . $tool, 0755);
        }
        $shell = str_replace('/tmp/baander-dsp-emsdk', $this->directory . '/sdk', $step['run'])
            |> (static fn(string $run): string => self::interpolate($run, self::context($server, matrix: ['mode' => $matrixMode])));
        self::assertStringNotContainsString('${{', $shell);
        $process = new Process(['/bin/bash', '-eo', 'pipefail', '-c', $shell], $this->directory, [
            'PATH' => $tools . ':' . getenv('PATH'),
            'CALL_LOG' => $log,
            'FAIL_COMMAND' => $command,
            'CI_IMAGE' => 'test-image',
            'CI_TEST_CONTAINER' => 'test-container',
            'CI_NETWORK' => 'test-network',
            'GITHUB_SERVER_URL' => $server,
        ]);
        $process->run();
        self::assertSame(47, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString($command, file_get_contents($log));
        self::assertContains($job, $jobs['build-and-push']['needs']);
        if ($stepName === 'Web checks') {
            self::assertStringNotContainsString('yarn build', file_get_contents($log));
        }
    }

    public function testAllWorkflowCheckoutsStayOnEventCommitAfterBranchAdvances(): void
    {
        $remote = $this->directory . '/origin.git';
        $writer = $this->directory . '/writer';
        $this->git(['init', '--bare', $remote]);
        $this->git(['init', '-b', 'master', $writer]);
        $this->git(['config', 'user.name', 'Workflow Test'], $writer);
        $this->git(['config', 'user.email', 'workflow@baander.app'], $writer);
        file_put_contents($writer . '/version', 'qualified');
        $this->git(['add', 'version'], $writer);
        $this->git(['commit', '-m', 'qualified event'], $writer);
        $sha = trim($this->git(['rev-parse', 'HEAD'], $writer));
        $this->git(['remote', 'add', 'origin', $remote], $writer);
        $this->git(['push', 'origin', 'master'], $writer);
        file_put_contents($writer . '/version', 'unqualified branch tip');
        $this->git(['commit', '-am', 'advance branch'], $writer);
        $this->git(['push', 'origin', 'master'], $writer);
        $advancedSha = trim($this->git(['rev-parse', 'HEAD'], $writer));

        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            foreach (self::GATE_WORKFLOWS as $file) {
                foreach ($this->workflow($file)['jobs'] as $jobName => $job) {
                    $checkout = $this->step($job, 'Checkout')['run'];
                    self::assertStringNotContainsString('github.ref', $checkout);
                    self::assertStringContainsString('git rev-parse HEAD', $checkout);
                    $workspace = $this->directory . '/' . parse_url($server, PHP_URL_HOST) . '-' . $file . '-' . $jobName;
                    new Filesystem()->mkdir($workspace);
                    $this->checkout($checkout, $remote, $sha, $server, $workspace)->mustRun();
                    self::assertSame($sha, trim($this->git(['rev-parse', 'HEAD'], $workspace)));
                    self::assertSame('qualified', file_get_contents($workspace . '/version'));
                }
            }
        }

        // Deliberately select the newer commit: the actual HEAD assertion must reject it.
        $checkout = $this->step($this->workflow('ci.yaml')['jobs']['build-and-push'], 'Checkout')['run'];
        $script = self::interpolate($checkout, self::checkoutContext($remote, $sha, self::GITHUB));
        $script = str_replace(
            'git checkout -q --detach "' . $sha . '"',
            'git fetch -q origin "' . $advancedSha . '"' . "\n"
                . 'git checkout -q --detach "' . $advancedSha . '"',
            $script,
        );
        $workspace = $this->directory . '/mismatched-head';
        new Filesystem()->mkdir($workspace);
        $process = new Process(['/bin/bash', '-e', '-c', $script], $workspace, ['GIT_TOKEN' => 'local-test-token', 'GITHUB_SERVER_URL' => self::GITHUB]);
        $process->run();
        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($advancedSha, trim($this->git(['rev-parse', 'HEAD'], $workspace)));
    }

    public function testPullRequestCheckoutQualifiesTheHeadCommitInsteadOfTheMergeCommit(): void
    {
        $remote = $this->directory . '/origin.git';
        $writer = $this->directory . '/writer';
        $this->git(['init', '--bare', $remote]);
        $this->git(['init', '-b', 'master', $writer]);
        $this->git(['config', 'user.name', 'Workflow Test'], $writer);
        $this->git(['config', 'user.email', 'workflow@baander.app'], $writer);
        file_put_contents($writer . '/version', 'pull request head');
        $this->git(['add', 'version'], $writer);
        $this->git(['commit', '-m', 'pull request head'], $writer);
        $head = trim($this->git(['rev-parse', 'HEAD'], $writer));
        $this->git(['remote', 'add', 'origin', $remote], $writer);
        $this->git(['push', 'origin', 'master:refs/heads/feature'], $writer);
        file_put_contents($writer . '/version', 'merge preview');
        $this->git(['commit', '-am', 'merge preview'], $writer);
        $merge = trim($this->git(['rev-parse', 'HEAD'], $writer));
        $this->git(['push', 'origin', 'master'], $writer);

        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            $checkout = $this->step($this->workflow('ci.yaml')['jobs']['quality-gate'], 'Checkout')['run'];
            $workspace = $this->directory . '/pull-request-' . parse_url($server, PHP_URL_HOST);
            new Filesystem()->mkdir($workspace);
            $this->checkout($checkout, $remote, $head, $server, $workspace, $merge)->mustRun();
            self::assertSame($head, trim($this->git(['rev-parse', 'HEAD'], $workspace)));
            self::assertSame('pull request head', file_get_contents($workspace . '/version'));
        }
    }

    public function testUnavailableEventCommitFailsCheckoutInsteadOfUsingBranchTip(): void
    {
        $remote = $this->directory . '/empty.git';
        $this->git(['init', '--bare', $remote]);
        $checkout = $this->step($this->workflow('ci.yaml')['jobs']['build-and-push'], 'Checkout')['run'];
        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            $workspace = $this->directory . '/checkout-' . parse_url($server, PHP_URL_HOST);
            new Filesystem()->mkdir($workspace);
            $process = $this->checkout($checkout, $remote, str_repeat('a', 40), $server, $workspace);
            $process->run();
            self::assertFalse($process->isSuccessful());
        }
    }

    public function testSharedTreeReplacesTheForgejoWorkflowDirectory(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertDirectoryDoesNotExist($root . '/.forgejo/workflows');
        foreach ([...self::GATE_WORKFLOWS, 'runner.yaml', 'postgres.yaml', 'nginx.yaml', 'ffmpeg.yaml', 'aac-native.yml'] as $file) {
            self::assertFileExists($root . '/.github/workflows/' . $file);
        }
        $documents = self::documents($root);
        self::assertSame([], self::treeViolations($documents), implode("\n", self::treeViolations($documents)));
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('treeViolationProvider')]
    public function testTreeContractRejectsEachForbiddenShape(string $path, callable $mutate, string $expected): void
    {
        $valid = self::syntheticWorkflow();
        self::assertSame([], self::treeViolations(['.github/workflows/synthetic.yaml' => ['source' => Yaml::dump($valid, 8), 'document' => $valid]]));
        $document = $mutate($path === '.github/workflows/synthetic.yaml' ? $valid : ['runs' => ['using' => 'composite', 'steps' => []]]);
        $violations = self::treeViolations([$path => ['source' => Yaml::dump($document, 8), 'document' => $document]]);
        self::assertNotSame([], array_filter($violations, static fn(string $line): bool => str_contains($line, $expected)), implode("\n", $violations));
    }

    /** @return iterable<string, array{string, callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function treeViolationProvider(): iterable
    {
        $workflow = '.github/workflows/synthetic.yaml';
        $step = static fn(array $step): callable => static function (array $document) use ($step): array {
            $document['jobs']['gate']['steps'][] = $step;
            return $document;
        };
        yield 'full-URL uses in a workflow' => [$workflow, $step(['uses' => 'https://code.forgejo.org/forgejo/upload-artifact@' . str_repeat('a', 40)]), 'full-URL'];
        yield 'full-URL uses in a shared action' => ['.github/actions/shared/action.yml', static function (array $document): array {
            $document['runs']['steps'][] = ['uses' => 'https://code.forgejo.org/forgejo/upload-artifact@' . str_repeat('a', 40)];
            return $document;
        }, 'full-URL'];
        yield 'Forgejo action outside its composite' => ['.github/actions/shared/action.yml', static function (array $document): array {
            $document['runs']['steps'][] = ['uses' => 'https://code.forgejo.org/forgejo/upload-artifact@v4'];
            return $document;
        }, 'not pinned'];
        yield 'unpinned action' => [$workflow, $step(['uses' => 'actions/cache/save@v4']), 'not pinned'];
        yield 'Forgejo context expression' => [$workflow, $step(['name' => 'Revision', 'run' => 'echo "${{ forgejo.sha }}"']), 'Forgejo-only'];
        yield 'Forgejo server version' => [$workflow, $step(['name' => 'Version', 'if' => 'github.forgejo_server_version', 'run' => 'true']), 'Forgejo-only'];
        yield 'write step without trusted-run guard' => [$workflow, $step(['name' => 'Save', 'if' => "github.ref == 'refs/heads/master'", 'uses' => 'actions/cache/save@' . str_repeat('a', 40)]), 'trusted-run'];
        yield 'login reachable from a fork' => [$workflow, $step(['name' => 'Login', 'run' => 'docker login registry.baander.app']), 'trusted-run'];
        yield 'host mount without forge guard' => [$workflow, $step(['name' => 'Clean', 'run' => 'docker run --rm -v /opt/ghc:/cleanup/ghc alpine true']), 'host paths'];
        yield 'checkout without safe directory' => [$workflow, $step(['name' => 'Checkout', 'run' => "git init -q\ngit fetch -q origin main"]), 'safe directory'];
        yield 'missing concurrency' => [$workflow, static function (array $document): array {
            unset($document['concurrency']);
            return $document;
        }, 'concurrency'];
        yield 'missing permissions' => [$workflow, static function (array $document): array {
            unset($document['permissions']);
            return $document;
        }, 'permissions'];
        yield 'packages write without publishing' => [$workflow, static function (array $document): array {
            $document['jobs']['gate']['permissions'] = ['contents' => 'read', 'packages' => 'write'];
            return $document;
        }, 'packages: write'];
        yield 'package store write without packages write' => [$workflow, $step([
            'name' => 'Save cache',
            'if' => "github.ref == 'refs/heads/master' && (" . self::TRUSTED . ')',
            'run' => 'php scripts/ci-package-store.php save "$CACHE_PACKAGE" cache.tar.zst /tmp/cache',
        ]), 'packages: write'];
        yield 'comment without pull-requests write' => [$workflow, $step([
            'name' => 'Comment',
            'if' => "github.event_name == 'pull_request' && (" . self::TRUSTED . ')',
            'run' => 'curl -X POST "$GITHUB_API_URL/repos/$GITHUB_REPOSITORY/issues/7/comments"',
        ]), 'pull-requests: write'];
        yield 'self-hosted label on GitHub' => [$workflow, static function (array $document): array {
            $document['jobs']['gate']['runs-on'] = 'docker-cli';
            return $document;
        }, 'runs-on'];
        yield 'unresolved timeout' => [$workflow, static function (array $document): array {
            $document['jobs']['gate']['timeout-minutes'] = '${{ vars.CI_TIMEOUT }}';
            return $document;
        }, 'timeout-minutes'];
    }

    public function testForgejoUploadCompositeWrapsTheOnlyPinnedFullUrlAction(): void
    {
        $root = dirname(__DIR__, 5);
        $path = '.github/actions/forgejo-upload-artifact/action.yml';
        $action = Yaml::parseFile($root . '/' . $path);
        self::assertSame([], self::treeViolations([$path => ['source' => (string) file_get_contents($root . '/' . $path), 'document' => $action]]));
        self::assertSame('composite', $action['runs']['using']);
        self::assertCount(1, $action['runs']['steps']);
        self::assertMatchesRegularExpression('~^https://code\.forgejo\.org/forgejo/upload-artifact@[0-9a-f]{40}$~', $action['runs']['steps'][0]['uses']);
        foreach (array_keys($action['inputs']) as $input) {
            self::assertSame('${{ inputs.' . $input . ' }}', $action['runs']['steps'][0]['with'][$input]);
        }

        $pairs = 0;
        foreach (self::GATE_WORKFLOWS as $file) {
            foreach ($this->workflow($file)['jobs'] as $jobName => $job) {
                foreach ($job['steps'] as $step) {
                    if (!str_starts_with($step['uses'] ?? '', 'actions/upload-artifact@')) {
                        continue;
                    }
                    ++$pairs;
                    $where = $file . ' ' . $jobName . ' ' . $step['name'];
                    self::assertSame(self::UPLOAD_ARTIFACT, $step['uses'], $where);
                    self::assertStringEndsWith(' on GitHub', $step['name'], $where);
                    $twin = $this->step($job, substr($step['name'], 0, -strlen('GitHub')) . 'Forgejo');
                    self::assertSame(self::FORGEJO_UPLOAD_ARTIFACT, $twin['uses'], $where);
                    self::assertSame($step['with'], $twin['with'], $where);
                    self::assertSame(14, $step['with']['retention-days'], $where);
                    self::assertSame([], array_diff(array_keys($twin['with']), array_keys($action['inputs'])), $where);
                    self::assertSame(str_replace(self::FORGE, "github.server_url != 'https://github.com'", $step['if']), $twin['if'], $where);
                    self::assertTrue(self::runs($step, self::context(self::GITHUB)), $where);
                    self::assertFalse(self::runs($step, self::context(self::FORGEJO)), $where);
                    self::assertTrue(self::runs($twin, self::context(self::FORGEJO)), $where);
                    self::assertFalse(self::runs($twin, self::context(self::GITHUB)), $where);
                }
            }
        }
        self::assertSame(5, $pairs);
        self::assertContains('.github/actions/**', $this->workflow('website.yaml')['on']['pull_request']['paths']);
    }

    #[DataProvider('publishedImageProvider')]
    public function testImagesResolveToEachForgesRegistry(string $file, string $name, bool $public): void
    {
        $workflow = $this->workflow($file);
        $github = self::context(self::GITHUB);
        $forgejo = self::context(self::FORGEJO);
        self::assertSame('ghcr.io', self::interpolate($workflow['env']['REGISTRY'], $github));
        self::assertSame('baander-app/' . $name, self::interpolate($workflow['env']['IMAGE_NAME'], $github));
        self::assertSame('registry.baander.app', self::interpolate($workflow['env']['REGISTRY'], $forgejo));
        self::assertSame('baander/' . $name, self::interpolate($workflow['env']['IMAGE_NAME'], $forgejo));
        foreach ($workflow['jobs'] as $job) {
            $image = $job['container']['image'] ?? null;
            if ($image === null || $image === 'docker:cli') {
                continue;
            }
            self::assertSame('ghcr.io/baander-app/baander-runner:latest', self::interpolate($image, $github));
            self::assertSame('registry.baander.app/baander/baander-runner:latest', self::interpolate($image, $forgejo));
        }
        $probes = array_filter(
            array_merge(...array_column($workflow['jobs'], 'steps')),
            static fn(array $step): bool => str_contains($step['run'] ?? '', 'scripts/ghcr-private-probe.sh'),
        );
        self::assertCount($public ? 0 : 1, $probes);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function publishedImageProvider(): iterable
    {
        yield 'application' => ['ci.yaml', 'baander', false];
        yield 'runner' => ['runner.yaml', 'baander-runner', true];
        yield 'PostgreSQL' => ['postgres.yaml', 'baander-postgres', true];
        yield 'Nginx' => ['nginx.yaml', 'baander-nginx', false];
        yield 'FFmpeg' => ['ffmpeg.yaml', 'ffmpeg-baander-static', false];
    }

    public function testCiPullsThePostgresImageFromEachForgesRegistry(): void
    {
        $workflow = $this->workflow('ci.yaml');
        self::assertSame('baander-app/baander-postgres', self::interpolate($workflow['env']['POSTGRES_IMAGE'], self::context(self::GITHUB)));
        self::assertSame('baander/baander-postgres', self::interpolate($workflow['env']['POSTGRES_IMAGE'], self::context(self::FORGEJO)));
        self::assertSame('docker pull ${{ env.REGISTRY }}/${{ env.POSTGRES_IMAGE }}:latest', $this->step($workflow['jobs']['quality-gate'], 'Pull PostgreSQL image')['run']);
    }

    #[DataProvider('privateImageProbeProvider')]
    public function testAnonymousPullProbePassesOnlyWhenTheRegistryRefusesThePull(
        string $file,
        string $job,
        string $stepName,
        string $pushStep,
        string $image,
        string $refusal,
        int $exitCode,
    ): void {
        $workflow = $this->workflow($file);
        $steps = $workflow['jobs'][$job]['steps'];
        $probe = $this->step($workflow['jobs'][$job], $stepName);
        self::assertFalse(self::runs($probe, self::context(self::FORGEJO)));
        self::assertTrue(self::runs($probe, self::context(self::GITHUB)));
        if (isset($workflow['on']['pull_request'])) {
            self::assertFalse(self::runs($probe, self::context(self::GITHUB, 'pull_request')));
        }
        $names = array_column($steps, 'name');
        self::assertGreaterThan(array_search($pushStep, $names, true), array_search($stepName, $names, true));

        $tools = $this->directory . '/tools';
        new Filesystem()->mkdir($tools);
        // The stub plays ghcr.io: it records the call and whether any credential was configured,
        // then serves the manifest or fails with the given error.
        $docker = <<<'SH'
#!/bin/sh
printf '%s config=%s\n' "$*" "$(ls -A "$DOCKER_CONFIG")" >> "$CALL_LOG"
if [ -z "$REFUSAL" ]; then
    printf '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.index.v1+json"}\n'
    exit 0
fi
printf '%s\n' "$REFUSAL" >&2
exit 1
SH;
        file_put_contents($tools . '/docker', $docker);
        chmod($tools . '/docker', 0755);
        $github = self::context(self::GITHUB);
        $process = new Process(['/bin/sh', '-e', '-c', self::interpolate($probe['run'], $github)], dirname(__DIR__, 5), [
            'PATH' => $tools . ':' . getenv('PATH'),
            'CALL_LOG' => $this->directory . '/probe-calls',
            'REFUSAL' => $refusal,
            'REGISTRY' => self::interpolate($workflow['env']['REGISTRY'], $github),
            'IMAGE_NAME' => self::interpolate($workflow['env']['IMAGE_NAME'], $github),
            'DOCKER_CONFIG' => $this->directory . '/credentials',
        ]);
        $process->run();

        self::assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('manifest inspect ' . $image . ":latest config=\n", file_get_contents($this->directory . '/probe-calls'));
    }

    /** @return iterable<string, array{string, string, string, string, string, string, int}> */
    public static function privateImageProbeProvider(): iterable
    {
        // Only a registry refusal confirms privacy; a local Docker or network failure cannot.
        $outcomes = [
            'served anonymously' => ['', 1],
            'refused as unauthorized' => ['unauthorized: authentication required', 0],
            'refused as an unknown manifest' => ['manifest unknown', 0],
            'unreachable through the Docker API' => ['permission denied while trying to connect to the docker API', 1],
            'unreachable through DNS' => ['dial tcp: lookup ghcr.io: no such host', 1],
        ];
        foreach ([
            'application' => ['ci.yaml', 'build-and-push', 'Application image is not publicly pullable', 'Push verified production image', 'ghcr.io/baander-app/baander'],
            'Nginx' => ['nginx.yaml', 'build', 'Nginx image is not publicly pullable', 'Build and push Nginx image', 'ghcr.io/baander-app/baander-nginx'],
            'FFmpeg' => ['ffmpeg.yaml', 'build', 'FFmpeg image is not publicly pullable', 'Build and push FFmpeg image', 'ghcr.io/baander-app/ffmpeg-baander-static'],
        ] as $name => $case) {
            foreach ($outcomes as $outcome => $result) {
                yield $name . ' ' . $outcome => [...$case, ...$result];
            }
        }
    }

    public function testForkPullRequestsRunEveryGateAndSkipEveryWriteStep(): void
    {
        $kinds = [];
        foreach (self::GATE_WORKFLOWS as $file) {
            foreach ($this->workflow($file)['jobs'] as $jobName => $job) {
                foreach ($job['steps'] as $step) {
                    if (!self::writes($step)) {
                        continue;
                    }
                    $kinds[self::writeKind($step)] = true;
                    foreach ([self::GITHUB, self::FORGEJO] as $server) {
                        self::assertFalse(self::runs($step, self::context($server, 'pull_request', self::FORK), $job), $file . ' ' . $jobName . ' ' . $step['name']);
                    }
                }
            }
        }
        ksort($kinds);
        self::assertSame(['cache save', 'comment', 'login', 'publish'], array_keys($kinds));

        $jobs = $this->workflow('ci.yaml')['jobs'];
        foreach (self::qualificationCommands() as [$job, $stepName]) {
            self::assertArrayNotHasKey('if', $jobs[$job]);
            self::assertTrue(self::runs($this->step($jobs[$job], $stepName), self::context(self::GITHUB, 'pull_request', self::FORK)));
        }
    }

    #[DataProvider('storeProvider')]
    public function testCachesAndBaselinesFollowTheEventOnEachForge(
        string $file,
        string $job,
        string $restore,
        string $save,
        string $package,
        bool $baseline,
    ): void {
        $workflow = $this->workflow($file);
        $steps = $workflow['jobs'][$job]['steps'];
        $names = array_column($steps, 'name');
        $forges = ['GitHub' => self::GITHUB, 'Forgejo' => self::FORGEJO];
        foreach ($forges as $forge => $server) {
            $other = $forge === 'GitHub' ? 'Forgejo' : 'GitHub';
            $pullRequest = self::context($server, 'pull_request');
            $master = self::context($server);
            self::assertTrue(self::runs($this->step($workflow['jobs'][$job], $restore . ' on ' . $forge), $pullRequest), $forge);
            self::assertFalse(self::runs($this->step($workflow['jobs'][$job], $restore . ' on ' . $other), $pullRequest), $forge);
            self::assertSame(!$baseline, self::runs($this->step($workflow['jobs'][$job], $restore . ' on ' . $forge), $master), $forge);
            self::assertFalse(self::runs($this->step($workflow['jobs'][$job], $save . ' on ' . $forge), $pullRequest), $forge);
            self::assertTrue(self::runs($this->step($workflow['jobs'][$job], $save . ' on ' . $forge), $master), $forge);
            self::assertFalse(self::runs($this->step($workflow['jobs'][$job], $save . ' on ' . $other), $master), $forge);
        }

        $githubRestore = $this->step($workflow['jobs'][$job], $restore . ' on GitHub');
        $githubSave = $this->step($workflow['jobs'][$job], $save . ' on GitHub');
        self::assertMatchesRegularExpression('~^actions/cache/restore@[0-9a-f]{40}$~', $githubRestore['uses']);
        self::assertSame(str_replace('/restore@', '/save@', $githubRestore['uses']), $githubSave['uses']);
        self::assertSame($githubRestore['with']['path'], $githubSave['with']['path']);
        self::assertSame($package . '-${{ github.sha }}', $githubSave['with']['key']);
        self::assertSame($package . '-${{ github.sha }}', $githubRestore['with']['key']);
        self::assertSame($package . '-', $githubRestore['with']['restore-keys']);

        $forgejoRestore = self::packageStoreCall($this->step($workflow['jobs'][$job], $restore . ' on Forgejo'), $workflow);
        $forgejoSave = self::packageStoreCall($this->step($workflow['jobs'][$job], $save . ' on Forgejo'), $workflow);
        self::assertSame($baseline ? ['fetch', 'store'] : ['restore', 'save'], [$forgejoRestore[0], $forgejoSave[0]]);
        self::assertSame([$package, $package], [$forgejoRestore[1], $forgejoSave[1]]);
        self::assertSame($forgejoRestore[2], $forgejoSave[2]);
        self::assertSame($forgejoRestore[3], $forgejoSave[3]);
        $githubPath = str_replace('~', '$HOME', $githubRestore['with']['path']);
        self::assertSame($baseline ? $githubPath . '/metrics.json' : $githubPath, $forgejoRestore[3]);

        $tooling = array_find_key($steps, static fn(array $step): bool => str_contains($step['run'] ?? '', 'composer --working-dir=scripts install'));
        self::assertNotNull($tooling, 'The job installs the standalone tooling before the package store runs.');
        self::assertTrue(self::runs($steps[$tooling], self::context(self::FORGEJO, 'pull_request')));
        self::assertLessThan(array_search($restore . ' on Forgejo', $names, true), $tooling);

        if ($baseline) {
            $comment = $this->step($workflow['jobs'][$job], 'Post coverage comment (PR only)');
            self::assertGreaterThan(array_search($restore . ' on Forgejo', $names, true), array_search($comment['name'], $names, true));
            self::assertGreaterThan(array_search($restore . ' on GitHub', $names, true), array_search($comment['name'], $names, true));
            self::assertStringContainsString('var/coverage-baseline/metrics.json', $comment['run']);
            $stage = $this->step($workflow['jobs'][$job], 'Stage coverage baseline (master only)');
            self::assertStringContainsString('var/coverage-baseline/metrics.json', $stage['run']);
            self::assertLessThan(array_search($save . ' on GitHub', $names, true), array_search($stage['name'], $names, true));
            foreach ([self::GITHUB, self::FORGEJO] as $server) {
                self::assertTrue(self::runs($comment, self::context($server, 'pull_request')), $server);
                self::assertFalse(self::runs($comment, self::context($server)), $server);
                self::assertFalse(self::runs($comment, self::context($server, 'pull_request', self::FORK)), $server);
                self::assertTrue(self::runs($stage, self::context($server)), $server);
                self::assertFalse(self::runs($stage, self::context($server, 'pull_request')), $server);
            }
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, bool}> */
    public static function storeProvider(): iterable
    {
        yield 'backend BuildKit cache' => ['ci.yaml', 'quality-gate', 'Restore BuildKit cache', 'Save BuildKit cache (master only)', 'buildkit-cache-backend', false];
        yield 'backend coverage baseline' => ['ci.yaml', 'quality-gate', 'Restore coverage baseline (PR only)', 'Store baseline (master only)', 'coverage-backend', true];
        yield 'frontend Yarn cache' => ['frontend.yaml', 'test', 'Restore yarn cache', 'Update yarn cache (master only)', 'yarn-cache-frontend', false];
        yield 'frontend coverage baseline' => ['frontend.yaml', 'test', 'Restore coverage baseline (PR only)', 'Store baseline (master only)', 'coverage-frontend', true];
    }

    public function testYarnCacheRestoresIntoTheJobUsersYarnCacheInEveryFrontendJob(): void
    {
        foreach ($this->workflow('frontend.yaml')['jobs'] as $jobName => $job) {
            $github = $this->step($job, 'Restore yarn cache on GitHub');
            self::assertSame('~/.yarn/berry/cache', $github['with']['path'], $jobName);
            self::assertSame(['restore', 'yarn-cache-frontend', 'yarn-cache.tar.zst', '$HOME/.yarn/berry/cache'], self::packageStoreCall($this->step($job, 'Restore yarn cache on Forgejo'), $this->workflow('frontend.yaml')), $jobName);
            $names = array_column($job['steps'], 'name');
            self::assertLessThan(array_search('Install dependencies', $names, true), array_search('Restore yarn cache on GitHub', $names, true), $jobName);
        }
    }

    public function testHostDiskCleanupRunsOnlyOnGitHubBeforeTheDiskHeavyJobs(): void
    {
        $workflow = $this->workflow('ci.yaml');
        foreach (['quality-gate', 'build-and-push'] as $jobName) {
            $step = $workflow['jobs'][$jobName]['steps'][0];
            self::assertSame('Free host disk on GitHub', $step['name']);
            self::assertTrue(self::runs($step, self::context(self::GITHUB)));
            self::assertTrue(self::runs($step, self::context(self::GITHUB, 'pull_request', self::FORK)));
            self::assertFalse(self::runs($step, self::context(self::FORGEJO)));

            $tools = $this->directory . '/tools-' . $jobName;
            new Filesystem()->mkdir($tools);
            file_put_contents($tools . '/docker', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$CALL_LOG\"\n");
            chmod($tools . '/docker', 0755);
            $log = $this->directory . '/cleanup-' . $jobName;
            $github = self::context(self::GITHUB);
            new Process(['/bin/sh', '-e', '-c', self::interpolate($step['run'], $github)], $this->directory, [
                'PATH' => $tools . ':' . getenv('PATH'),
                'CALL_LOG' => $log,
                'REGISTRY' => self::interpolate($workflow['env']['REGISTRY'], $github),
                'IMAGE_NAME' => self::interpolate($workflow['env']['IMAGE_NAME'], $github),
            ])->mustRun();
            $arguments = file($log, FILE_IGNORE_NEW_LINES);
            self::assertSame(['run', '--rm'], array_slice($arguments, 0, 2));
            self::assertContains('ghcr.io/baander-app/baander-runner:latest', $arguments);
            foreach (['/usr/share/dotnet', '/usr/local/lib/android', '/opt/ghc', '/opt/hostedtoolcache'] as $directory) {
                self::assertNotEmpty(preg_grep('~^' . preg_quote($directory, '~') . ':/~', $arguments), $directory);
            }
            self::assertStringContainsString('df ', implode(' ', $arguments));
        }
    }

    public function testAacNativeJobsSkipOnForgejoAndKeepTheirHostedLabelsOnGitHub(): void
    {
        $jobs = $this->workflow('aac-native.yml')['jobs'];
        $labels = [];
        foreach ($jobs['build']['strategy']['matrix']['include'] as $entry) {
            self::assertSame('docker-cli', self::interpolate($jobs['build']['runs-on'], self::context(self::FORGEJO, matrix: $entry)));
            $labels[] = self::interpolate($jobs['build']['runs-on'], self::context(self::GITHUB, matrix: $entry));
        }
        self::assertSame(['ubuntu-latest', 'windows-2022', 'macos-15-intel', 'macos-latest'], $labels);
        self::assertSame('ubuntu-latest', self::interpolate($jobs['release']['runs-on'], self::context(self::GITHUB)));
        $tag = self::context(self::GITHUB);
        $tag['github']['ref'] = 'refs/tags/aac-native-v1.0.0';
        self::assertTrue(self::condition($jobs['release']['if'], $tag));
        foreach ($jobs as $name => $job) {
            self::assertStringContainsString(self::FORGE, $job['if'], $name);
            self::assertSame('docker-cli', self::interpolate($job['runs-on'], self::context(self::FORGEJO, matrix: ['os' => 'macos-latest'])), $name);
            self::assertFalse(self::condition($job['if'], self::context(self::FORGEJO)), $name);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4?: string}> */
    public static function failedQualificationProvider(): iterable
    {
        foreach (['GitHub' => self::GITHUB, 'Forgejo' => self::FORGEJO] as $forge => $server) {
            foreach (self::qualificationCommands() as $name => $case) {
                if ($name === 'registry') {
                    foreach (['release', 'sanitize'] as $mode) {
                        yield $forge . ' ' . $name . ' ' . $mode => [$server, ...$case, $mode];
                    }
                    continue;
                }
                yield $forge . ' ' . $name => [$server, ...$case];
            }
        }
    }

    /** @return array<string, array{string, string, string}> */
    private static function qualificationCommands(): array
    {
        return [
            'PHPStan' => ['quality-gate', 'PHPStan', './vendor/bin/phpstan analyse'],
            'specification drift' => ['quality-gate', 'OpenAPI drift', 'app:export-openapi-spec --env=test --no-debug --check'],
            'client drift' => ['frontend-quality', 'Web checks', 'yarn generate:check'],
            'Deptrac' => ['quality-gate', 'Deptrac', 'vendor/bin/deptrac analyse'],
            'backend unit' => ['quality-gate', 'PHPUnit with coverage', 'scripts/test-backend-container.sh'],
            'backend runner' => ['quality-gate', 'Backend runner regressions', 'scripts/tests/php/RetainBuildEvidenceTest.php'],
            'PHP tooling format' => ['quality-gate', 'PHP tooling regressions', 'composer check:tooling-format'],
            'PHP tooling unit' => ['quality-gate', 'PHP tooling regressions', '--exclude-group registry-python-subject'],
            'PHP tooling subject' => ['quality-gate', 'PHP tooling regressions', '--group registry-python-subject'],
            'PHP tooling static' => ['quality-gate', 'PHP tooling regressions', 'php vendor/bin/phpstan analyse --configuration phpstan-tooling.neon'],
            'backend isolation' => ['quality-gate', 'Backend stack isolation', 'php scripts/test-backend-stack-isolation.php'],
            'Messenger without Swoole' => ['quality-gate', 'Messenger without Swoole', 'scripts/test-backend-container.sh'],
            'coverage integrity' => ['quality-gate', 'Extract coverage metrics', 'php scripts/validate-backend-coverage.php'],
            'web unit' => ['frontend-quality', 'Web checks', 'yarn test'],
            'browser auth' => ['frontend-quality', 'Native browser authentication transport', 'yarn test:browser-auth'],
            'browser audio' => ['frontend-quality', 'Native browser audio graph', 'yarn test:audio-graph'],
            'browser debug' => ['frontend-quality', 'Store isolation and debugger browser workflow', 'yarn test:store-debug'],
            'embedded typecheck' => ['frontend-quality', 'Embedded player checks', 'corepack yarn typecheck'],
            'embedded unit' => ['frontend-quality', 'Embedded player checks', 'corepack yarn test'],
            'DSP' => ['dsp-quality', 'Build and qualify analysis modules', 'bash scripts/test-dsp-analysis.sh'],
            'registry' => ['registry-quality', 'Build and qualify registry', 'bash scripts/test-registry.sh'],
            'registry thread' => ['registry-thread-quality', 'Build and qualify registry threads', 'bash scripts/test-registry.sh thread'],
            'registry container' => ['registry-container-quality', 'Build and qualify registry containers', 'bash scripts/test-registry-container.sh'],
            'registry fuzz' => ['registry-fuzz-quality', 'Fuzz registry parser and registration validation', 'bash scripts/test-registry.sh fuzz'],
            'registry static' => ['registry-static-quality', 'Analyze registry and verify direct dependency licenses', 'bash scripts/test-registry-static.sh'],
        ];
    }

    /** @return array<string, mixed> */
    private function workflow(string $name): array
    {
        return Yaml::parseFile(dirname(__DIR__, 5) . '/.github/workflows/' . $name);
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    private function step(array $job, string $name): array
    {
        foreach ($job['steps'] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        self::fail('Missing workflow step: ' . $name);
    }

    private function checkout(string $script, string $remote, string $sha, string $server, string $workspace, ?string $mergeSha = null): Process
    {
        return new Process(
            ['/bin/bash', '-e', '-c', self::interpolate($script, self::checkoutContext($remote, $sha, $server, $mergeSha))],
            $workspace,
            ['GIT_TOKEN' => 'local-test-token', 'GITHUB_SERVER_URL' => $server, 'HOME' => $this->home(), 'GIT_CONFIG_NOSYSTEM' => '1'],
        );
    }

    /** A throwaway home directory, so the checkout's global git configuration never reaches the developer's own. */
    private function home(): string
    {
        $home = $this->directory . '/home';
        new Filesystem()->mkdir($home);
        return $home;
    }

    /** @return array<string, mixed> */
    private static function checkoutContext(string $remote, string $sha, string $server, ?string $mergeSha = null): array
    {
        $context = self::context($server, $mergeSha === null ? 'push' : 'pull_request');
        $context['github']['event']['repository']['clone_url'] = escapeshellarg($remote);
        $context['github']['sha'] = $mergeSha ?? $sha;
        if ($mergeSha !== null) {
            $context['github']['event']['pull_request']['head']['sha'] = $sha;
        }
        return $context;
    }

    /**
     * Expression contexts for one forge and event. A pull request comes from this repository
     * unless a head repository is given.
     *
     * @param array<string, mixed> $matrix
     * @return array<string, mixed>
     */
    private static function context(string $server, string $event = 'push', ?string $headRepository = null, array $matrix = []): array
    {
        $payload = ['repository' => ['clone_url' => $server . '/' . self::REPOSITORY . '.git']];
        if ($event === 'pull_request') {
            $payload['number'] = 7;
            $payload['pull_request'] = ['head' => ['sha' => str_repeat('b', 40), 'repo' => ['full_name' => $headRepository ?? self::REPOSITORY]]];
        }
        return [
            'github' => [
                'server_url' => $server,
                'event_name' => $event,
                'ref' => $event === 'pull_request' ? 'refs/pull/7/merge' : 'refs/heads/master',
                'repository' => self::REPOSITORY,
                'actor' => 'baander-ci',
                'sha' => str_repeat('c', 40),
                'run_id' => '101',
                'run_attempt' => '1',
                'token' => 'local-test-token',
                'event' => $payload,
            ],
            'vars' => ['REGISTRY_URL' => 'registry.baander.app', 'REGISTRY_OWNER' => 'baander'],
            'secrets' => ['GITHUB_TOKEN' => 'local-test-token', 'REGISTRY_TOKEN' => 'registry-token'],
            'matrix' => $matrix,
            'runner' => ['temp' => '/tmp'],
            'env' => [],
            'inputs' => [],
        ];
    }

    public function testCacheExportingBuildsUseAContainerBuilderPreparedOnBothForges(): void
    {
        $builds = 0;
        foreach (self::GATE_WORKFLOWS as $file) {
            foreach ($this->workflow($file)['jobs'] as $jobName => $job) {
                $names = array_column($job['steps'], 'name');
                foreach ($job['steps'] as $index => $step) {
                    $run = $step['run'] ?? '';
                    if (!str_contains($run, 'build-application.php') || preg_match('~--cache-(?:to|from)\b~', $run) !== 1) {
                        continue;
                    }
                    ++$builds;
                    self::assertStringContainsString('--builder "$CI_BUILDER"', $run, $file . ' ' . $jobName . ' ' . $step['name']);
                    $prepare = array_search('Prepare BuildKit container builder', $names, true);
                    self::assertIsInt($prepare, $file . ' ' . $jobName);
                    self::assertLessThan($index, $prepare, $file . ' ' . $jobName . ' ' . $step['name']);
                }
            }
        }
        self::assertSame(2, $builds);

        $gate = $this->workflow('ci.yaml')['jobs']['quality-gate'];
        self::assertSame('baander-ci-${{ github.run_id }}-${{ github.run_attempt }}', $gate['env']['CI_BUILDER']);
        $prepare = $this->step($gate, 'Prepare BuildKit container builder');
        $remove = $this->step($gate, 'Remove BuildKit container builder');
        self::assertSame('always()', $remove['if']);
        self::assertSame('Remove BuildKit container builder', $gate['steps'][array_key_last($gate['steps'])]['name']);
        foreach ([self::GITHUB, self::FORGEJO] as $server) {
            foreach ([self::context($server), self::context($server, 'pull_request'), self::context($server, 'pull_request', self::FORK)] as $context) {
                self::assertTrue(self::runs($prepare, $context, $gate), $server);
            }
        }
        self::assertNull(self::writeKind($prepare));

        $tools = $this->directory . '/tools-builder';
        new Filesystem()->mkdir($tools);
        file_put_contents($tools . '/docker', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$CALL_LOG\"\n[ \"\$2\" != rm ] || exit 1\n");
        chmod($tools . '/docker', 0755);
        $log = $this->directory . '/builder-calls';
        $environment = [
            'PATH' => $tools . ':' . getenv('PATH'),
            'CALL_LOG' => $log,
            'CI_BUILDER' => 'baander-ci-101-1',
        ];
        foreach ([$prepare, $remove] as $step) {
            new Process(['/bin/sh', '-e', '-c', self::interpolate($step['run'], self::context(self::GITHUB))], $this->directory, $environment)->mustRun();
        }
        $calls = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($calls);
        self::assertMatchesRegularExpression(
            '~^buildx create --name baander-ci-101-1 --driver docker-container --driver-opt image=moby/buildkit:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$~',
            $calls[0] ?? '',
        );
        self::assertSame(['buildx rm baander-ci-101-1'], array_slice($calls, 1));
    }

    /** @return array<string, array{source: string, document: array<string, mixed>}> */
    private static function documents(string $root): array
    {
        $documents = [];
        $files = [
            ...glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE),
            ...glob($root . '/.github/actions/*/action.{yml,yaml}', GLOB_BRACE),
        ];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $documents[substr($file, strlen($root) + 1)] = ['source' => $source, 'document' => Yaml::parse($source)];
        }
        return $documents;
    }

    /** @return array<string, mixed> */
    private static function syntheticWorkflow(): array
    {
        return [
            'name' => 'Synthetic',
            'on' => ['pull_request' => ['types' => ['opened']], 'push' => ['branches' => ['master']]],
            'permissions' => ['contents' => 'read'],
            'concurrency' => ['group' => 'synthetic-${{ github.ref }}', 'cancel-in-progress' => self::CANCEL_PULL_REQUESTS],
            'jobs' => ['gate' => [
                'runs-on' => "\${{ github.server_url == 'https://github.com' && 'ubuntu-latest' || 'docker-cli' }}",
                'steps' => [['name' => 'Gate', 'run' => 'true']],
            ]],
        ];
    }

    /**
     * Every rule the shared tree must keep on both forges.
     *
     * @param array<string, array{source: string, document: array<string, mixed>}> $documents
     * @return list<string>
     */
    private static function treeViolations(array $documents): array
    {
        $violations = [];
        foreach ($documents as $path => ['source' => $source, 'document' => $document]) {
            if (preg_match('~(?<![\w./-])forgejo\.[a-z_]|forgejo_server_version~', $source)) {
                $violations[] = $path . ': uses a Forgejo-only context; use github.* on both forges';
            }
            $isWorkflow = str_starts_with($path, '.github/workflows/');
            $forgejoComposite = str_starts_with($path, '.github/actions/forgejo-');
            $steps = $isWorkflow
                ? array_merge(...array_values(array_map(static fn(array $job): array => $job['steps'] ?? [], $document['jobs'] ?? [])))
                : $document['runs']['steps'] ?? [];
            foreach ($steps as $step) {
                $uses = $step['uses'] ?? null;
                if ($uses === null || str_starts_with($uses, './')) {
                    continue;
                }
                $label = $path . ': ' . $uses;
                if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $uses)) {
                    if (!$forgejoComposite || !str_starts_with($uses, 'https://code.forgejo.org/forgejo/')) {
                        $violations[] = $label . ' is a full-URL action reference';
                    }
                }
                if (!preg_match('~@[0-9a-f]{40}$~', $uses)) {
                    $violations[] = $label . ' is not pinned to a commit SHA';
                }
            }
            if ($isWorkflow) {
                array_push($violations, ...self::workflowViolations($path, $document));
            }
        }
        return $violations;
    }

    /**
     * @param array<string, mixed> $workflow
     * @return list<string>
     */
    private static function workflowViolations(string $path, array $workflow): array
    {
        $violations = [];
        if (!isset($workflow['concurrency']['group']) || ($workflow['concurrency']['cancel-in-progress'] ?? null) !== self::CANCEL_PULL_REQUESTS) {
            $violations[] = $path . ': needs concurrency per ref that cancels in progress only for pull requests';
        }
        $permissions = $workflow['permissions'] ?? null;
        if (!is_array($permissions) || in_array('write', $permissions, true)) {
            $violations[] = $path . ': needs top-level permissions without write access';
        }
        $triggers = is_array($workflow['on'] ?? null) ? (array_is_list($workflow['on']) ? $workflow['on'] : array_keys($workflow['on'])) : [$workflow['on'] ?? ''];
        $pullRequests = in_array('pull_request', $triggers, true);
        foreach ($workflow['jobs'] ?? [] as $name => $job) {
            $where = $path . ' ' . $name;
            $steps = $job['steps'] ?? [];
            $writesPackages = array_any($steps, self::writesPackages(...));
            $comments = array_any($steps, static fn(array $step): bool => self::writeKind($step) === 'comment');
            if ($writesPackages !== (($job['permissions']['packages'] ?? null) === 'write')) {
                $violations[] = $where . ': only jobs that publish or write to the package store request packages: write, and every such job does';
            }
            if ($comments !== (($job['permissions']['pull-requests'] ?? null) === 'write')) {
                $violations[] = $where . ': only commenting jobs request pull-requests: write, and every commenting job does';
            }
            $matrix = $job['strategy']['matrix'] ?? [];
            $entries = $matrix['include'] ?? [array_map(static fn(mixed $value): mixed => is_array($value) ? $value[0] : $value, $matrix)];
            foreach ($entries as $entry) {
                $github = self::interpolate($job['runs-on'] ?? '', self::context(self::GITHUB, matrix: $entry));
                $forgejo = self::interpolate($job['runs-on'] ?? '', self::context(self::FORGEJO, matrix: $entry));
                if (!is_string($github) || !preg_match('~^(?:ubuntu|windows|macos)-[\w.-]+$~', $github) || $forgejo !== 'docker-cli') {
                    $violations[] = $where . ': runs-on must resolve to a hosted label on GitHub and docker-cli on Forgejo';
                }
            }
            foreach ([$job, ...$steps] as $subject) {
                foreach ([self::GITHUB, self::FORGEJO] as $server) {
                    $timeout = self::timeout($subject, self::context($server));
                    if ($timeout !== null && $timeout <= 0) {
                        $violations[] = $where . ': timeout-minutes must resolve to minutes on ' . $server;
                    }
                }
            }
            foreach ($steps as $step) {
                $label = $where . ' ' . ($step['name'] ?? $step['uses'] ?? 'step');
                $run = $step['run'] ?? '';
                $init = preg_match('~^\s*git init -q\s*$~m', $run, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : null;
                $safe = strpos($run, 'git config --global --add safe.directory "$PWD"');
                if ($init !== null && ($safe === false || $safe > $init)) {
                    $violations[] = $label . ': must trust the workspace as a safe directory before initialising it, because the job container user does not own it on GitHub';
                }
                if ($pullRequests && self::writes($step)) {
                    $fork = array_any(
                        [self::GITHUB, self::FORGEJO],
                        static fn(string $server): bool => self::runs($step, self::context($server, 'pull_request', self::FORK), $job),
                    );
                    if (!str_contains((string) ($step['if'] ?? ''), self::TRUSTED) || $fork) {
                        $violations[] = $label . ': write step lacks the trusted-run guard';
                    }
                }
                if (preg_match('~(?:^|\s)(?:-v|--volume)[=\s]+["\']?/|--mount[=\s][^\n]*\b(?:src|source)=/~m', $step['run'] ?? '')
                    && (!str_contains((string) ($step['if'] ?? ''), self::FORGE) || self::runs($step, self::context(self::FORGEJO), $job))) {
                    $violations[] = $label . ': mounts host paths without the GitHub-only forge guard';
                }
            }
        }
        return $violations;
    }

    /** @param array<string, mixed> $step */
    private static function writes(array $step): bool
    {
        return self::writeKind($step) !== null;
    }

    /**
     * Publishing an image or saving to the Forgejo package store; Forgejo may scope its workflow
     * token by the job's permissions, so both need packages: write.
     *
     * @param array<string, mixed> $step
     */
    private static function writesPackages(array $step): bool
    {
        return self::writeKind($step) === 'publish' || preg_match('~ci-package-store\.php (?:save|store)\b~', $step['run'] ?? '') === 1;
    }

    /** @param array<string, mixed> $step */
    private static function writeKind(array $step): ?string
    {
        $run = $step['run'] ?? '';
        return match (true) {
            str_starts_with($step['uses'] ?? '', 'actions/cache/save@'),
            preg_match('~ci-package-store\.php (?:save|store)\b~', $run) === 1 => 'cache save',
            str_contains($run, 'docker login') => 'login',
            preg_match('~docker push|--push\b~', $run) === 1 => 'publish',
            preg_match('~/issues/[^/\s]+/comments~', $run) === 1 => 'comment',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $workflow
     * @return list<string> operation, package, file and path of the package store call
     */
    private static function packageStoreCall(array $step, array $workflow): array
    {
        self::assertMatchesRegularExpression('~^php scripts/ci-package-store\.php (\w+) "\$(\w+)" (\S+) "?([^"\s]+)"?$~', trim($step['run']));
        preg_match('~^php scripts/ci-package-store\.php (\w+) "\$(\w+)" (\S+) "?([^"\s]+)"?$~', trim($step['run']), $call);
        self::assertSame('${{ secrets.GITHUB_TOKEN }}', $step['env']['FORGEJO_TOKEN'] ?? null);
        return [$call[1], $workflow['env'][$call[2]], $call[3], $call[4]];
    }

    /** @param array<string, mixed> $subject
     *  @param array<string, mixed> $context
     */
    private static function timeout(array $subject, array $context): ?int
    {
        if (!isset($subject['timeout-minutes'])) {
            return null;
        }
        $value = self::interpolate($subject['timeout-minutes'], $context);
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    /**
     * Whether a step runs, given its job's condition. A missing condition means success().
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $context
     * @param array<string, mixed> $job
     */
    private static function runs(array $step, array $context, array $job = []): bool
    {
        return self::condition($job['if'] ?? null, $context) && self::condition($step['if'] ?? null, $context);
    }

    /** @param array<string, mixed> $context */
    private static function condition(mixed $condition, array $context): bool
    {
        if ($condition === null || is_bool($condition)) {
            return $condition ?? true;
        }
        $expression = preg_match('~^\s*\$\{\{(.*)\}\}\s*$~s', (string) $condition, $match) ? $match[1] : (string) $condition;
        return self::truthy(self::evaluate($expression, $context));
    }

    /**
     * Replaces every `${{ }}` expression; a value that is one expression keeps its type.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(mixed $value, array $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('~^\$\{\{((?:(?!\}\}).)*)\}\}$~s', $value, $match)) {
            return self::evaluate($match[1], $context);
        }
        return preg_replace_callback(
            '~\$\{\{((?:(?!\}\}).)*)\}\}~s',
            static fn(array $match): string => self::text(self::evaluate($match[1], $context)),
            $value,
        );
    }

    /**
     * Evaluates the expression subset the workflows use: literals, context properties, `!`,
     * `==`, `!=`, `&&`, `||`, parentheses and the status and string functions.
     *
     * @param array<string, mixed> $context
     */
    private static function evaluate(string $expression, array $context): mixed
    {
        $tokens = [];
        $source = trim($expression);
        for ($offset = 0; $offset < strlen($source); $offset += strlen($match[0])) {
            if (!preg_match('~\G\s*(\|\||&&|==|!=|[!()\[\],.]|\'(?:[^\']|\'\')*\'|\d+(?:\.\d+)?|[A-Za-z_][\w-]*|\*)\s*~', $source, $match, 0, $offset)) {
                self::fail('Cannot tokenize expression: ' . $expression);
            }
            $tokens[] = $match[1];
        }
        $position = 0;
        $value = self::parseOr($tokens, $position, $context);
        self::assertSame(count($tokens), $position, 'Unparsed expression: ' . $expression);
        return $value;
    }

    /** @param list<string> $tokens
     *  @param array<string, mixed> $context
     */
    private static function parseOr(array $tokens, int &$position, array $context): mixed
    {
        $value = self::parseAnd($tokens, $position, $context);
        while (($tokens[$position] ?? null) === '||') {
            ++$position;
            $right = self::parseAnd($tokens, $position, $context);
            $value = self::truthy($value) ? $value : $right;
        }
        return $value;
    }

    /** @param list<string> $tokens
     *  @param array<string, mixed> $context
     */
    private static function parseAnd(array $tokens, int &$position, array $context): mixed
    {
        $value = self::parseEquality($tokens, $position, $context);
        while (($tokens[$position] ?? null) === '&&') {
            ++$position;
            $right = self::parseEquality($tokens, $position, $context);
            $value = self::truthy($value) ? $right : $value;
        }
        return $value;
    }

    /** @param list<string> $tokens
     *  @param array<string, mixed> $context
     */
    private static function parseEquality(array $tokens, int &$position, array $context): mixed
    {
        $value = self::parseUnary($tokens, $position, $context);
        while (in_array($tokens[$position] ?? null, ['==', '!='], true)) {
            $operator = $tokens[$position++];
            $equal = self::equals($value, self::parseUnary($tokens, $position, $context));
            $value = $operator === '==' ? $equal : !$equal;
        }
        return $value;
    }

    /** @param list<string> $tokens
     *  @param array<string, mixed> $context
     */
    private static function parseUnary(array $tokens, int &$position, array $context): mixed
    {
        if (($tokens[$position] ?? null) === '!') {
            ++$position;
            return !self::truthy(self::parseUnary($tokens, $position, $context));
        }
        return self::parsePrimary($tokens, $position, $context);
    }

    /** @param list<string> $tokens
     *  @param array<string, mixed> $context
     */
    private static function parsePrimary(array $tokens, int &$position, array $context): mixed
    {
        $token = $tokens[$position++] ?? self::fail('Unexpected end of expression');
        if ($token === '(') {
            $value = self::parseOr($tokens, $position, $context);
            self::assertSame(')', $tokens[$position++] ?? null);
            return $value;
        }
        if ($token[0] === "'") {
            return str_replace("''", "'", substr($token, 1, -1));
        }
        if (is_numeric($token)) {
            return $token + 0;
        }
        $literal = match (strtolower($token)) {
            'true' => [true],
            'false' => [false],
            'null' => [null],
            default => [],
        };
        if ($literal !== []) {
            return $literal[0];
        }
        if (($tokens[$position] ?? null) === '(') {
            ++$position;
            $arguments = [];
            while (($tokens[$position] ?? null) !== ')') {
                $arguments[] = self::parseOr($tokens, $position, $context);
                if (($tokens[$position] ?? null) === ',') {
                    ++$position;
                }
            }
            ++$position;
            $text = array_map(self::text(...), $arguments);
            return match (strtolower($token)) {
                'always', 'success' => true,
                'failure', 'cancelled' => false,
                'startswith' => str_starts_with(strtolower($text[0]), strtolower($text[1])),
                'endswith' => str_ends_with(strtolower($text[0]), strtolower($text[1])),
                'contains' => str_contains(strtolower($text[0]), strtolower($text[1])),
                default => self::fail('Unsupported expression function: ' . $token),
            };
        }
        $value = $context[$token] ?? null;
        while (in_array($tokens[$position] ?? null, ['.', '['], true)) {
            if ($tokens[$position++] === '.') {
                $key = $tokens[$position++];
            } else {
                $key = self::text(self::parseOr($tokens, $position, $context));
                self::assertSame(']', $tokens[$position++] ?? null);
            }
            $value = is_array($value) ? $value[$key] ?? null : null;
        }
        return $value;
    }

    private static function equals(mixed $left, mixed $right): bool
    {
        if (is_string($left) && is_string($right)) {
            return strcasecmp($left, $right) === 0;
        }
        if (gettype($left) === gettype($right)) {
            return $left === $right;
        }
        $number = static fn(mixed $value): float => match (true) {
            $value === null => 0.0,
            is_bool($value) => (float) $value,
            is_numeric($value) => (float) $value,
            default => NAN,
        };
        return $number($left) === $number($right);
    }

    private static function truthy(mixed $value): bool
    {
        return !in_array($value, [null, false, 0, 0.0, ''], true);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    private function registryRecipe(string $mode, bool $configured, int $validationExit = 0, int $storageHelperExit = 0): Process
    {
        $tools = $this->directory . '/tools';
        $root = dirname(__DIR__, 5);
        new Filesystem()->mkdir([$tools, $this->directory . '/scripts', $this->directory . '/relay/tests', $this->directory . '/relay/vendor']);
        foreach (['test-registry.sh', 'test-registry.php', 'bootstrap.php'] as $name) {
            copy($root . '/scripts/' . $name, $this->directory . '/scripts/' . $name);
        }
        symlink($root . '/scripts/vendor', $this->directory . '/scripts/vendor');
        // Keep the real launcher and orchestrator; isolate the dedicated mount and harness processes.
        $storage = <<<'PHP'
<?php
namespace Baander\Registry\Qualification;
final class BoundedStorage
{
    public static function validateRoot(string $root): array
    {
        file_put_contents(getenv('CALL_LOG'), 'validateRoot ' . $root . "\n", FILE_APPEND);
        $status = (int) getenv('VALIDATION_EXIT');
        if ($status !== 0) {
            throw new \RuntimeException('Unsafe storage fixture.', $status);
        }
        return [];
    }
}
PHP;
        file_put_contents(
            $this->directory . '/relay/tests/BoundedStorage.php',
            $storage,
        );
        file_put_contents(
            $this->directory . '/relay/vendor/autoload.php',
            "<?php\n\$loader = require " . var_export($root . '/relay/vendor/autoload.php', true) . ";\n"
            . '$loader->addClassMap('
            . var_export(['Baander\\Registry\\Qualification\\BoundedStorage' => $this->directory . '/relay/tests/BoundedStorage.php'], true)
            . ");\nreturn \$loader;\n",
        );
        $contract = <<<'PHP'
<?php
$name = basename(__FILE__);
file_put_contents(getenv('CALL_LOG'), $name . ' ' . implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
exit(match ($name) {
    'test_storage_cleanup.php' => (int) getenv('STORAGE_HELPER_EXIT'),
    'run_storage_contract.php' => 47,
    default => 0,
});
PHP;
        foreach ([
            'test_cluster_cleanup',
            'test_storage_cleanup',
            'test_runtime_lifecycle',
            'test_cluster_convergence',
            'run_rqlite_contract',
            'run_transport_contract',
            'run_http_contract',
            'run_cluster_contract',
            'run_restore_contract',
            'run_storage_contract',
            'run_load_smoke',
        ] as $name) {
            file_put_contents($this->directory . '/relay/tests/' . $name . '.php', $contract);
        }
        $stub = <<<'SH'
#!/bin/bash
tool="${0##*/}"
printf '%s %s\n' "$tool" "$*" >> "$CALL_LOG"
case "$tool" in
    tar)
        if [[ "$*" == *openssl.tar.gz* ]]; then
            mkdir -p "$4/openssl-3.5.3"
            printf '#!/bin/sh\nexit 0\n' > "$4/openssl-3.5.3/Configure"
            chmod +x "$4/openssl-3.5.3/Configure"
        fi
        ;;
esac
exit 0
SH;
        foreach (['cmake', 'ninja', 'c++', 'clang', 'clang++', 'make', 'perl', 'curl', 'openssl', 'python3', 'sha256sum', 'pkg-config', 'tar', 'ctest'] as $tool) {
            file_put_contents($tools . '/' . $tool, $stub);
            chmod($tools . '/' . $tool, 0755);
        }

        $process = new Process(['/bin/bash', 'scripts/test-registry.sh', $mode], $this->directory, [
            'PATH' => $tools . ':' . getenv('PATH'),
            'CALL_LOG' => $this->directory . '/registry-calls',
            'VALIDATION_EXIT' => (string) $validationExit,
            'STORAGE_HELPER_EXIT' => (string) $storageHelperExit,
            'REGISTRY_FULL_DISK_ROOT' => $configured ? '/run/baander-registry-full-disk' : false,
        ]);
        $process->setTimeout(10);
        return $process;
    }

    private function registryCalls(): string
    {
        $log = $this->directory . '/registry-calls';
        return file_exists($log) ? file_get_contents($log) : '';
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, ?string $directory = null): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->mustRun();
        return $process->getOutput();
    }
}
