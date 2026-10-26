<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Local workflow contracts and real shell checks; this does not run a Forgejo scheduler. */
final class PublicationWorkflowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-publication-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
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

        $build = $this->step($publisher, 'Build production image');
        self::assertStringContainsString('--target production', $build['run']);
        self::assertStringContainsString('--load', $build['run']);
        self::assertArrayNotHasKey('if', $build);

        $inventory = $this->step($publisher, 'Collect and verify production packaged inventory');
        self::assertStringContainsString('scripts/collect-packaged-inventory.sh', $inventory['run']);
        self::assertArrayNotHasKey('if', $inventory);
        self::assertArrayNotHasKey('continue-on-error', $inventory);

        $publication = $this->step($publisher, 'Push verified production image');
        self::assertSame("forgejo.ref == 'refs/heads/master'", $publication['if']);
        self::assertStringContainsString('docker push "$REGISTRY/$IMAGE_NAME:${{ forgejo.sha }}"', $publication['run']);
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
        self::assertSame(45, $job['timeout-minutes']);
        self::assertSame(
            $this->step($existing, 'Install parser fuzz toolchain')['run'],
            $this->step($job, 'Install parser fuzz toolchain')['run'],
        );
        self::assertSame('python3 relay/tests/test_qualification_runner.py', $this->step($job, 'Verify qualification orchestration')['run']);
        $run = $this->step($job, 'Qualify five-voter Registry');
        self::assertSame('bash scripts/test-registry.sh fuzz', $run['run']);
        self::assertSame('5', $run['env']['REGISTRY_CLUSTER_NODES']);
        self::assertSame('10000', $run['env']['REGISTRY_FUZZ_RUNS']);
        self::assertSame('120', $run['env']['REGISTRY_FUZZ_SECONDS']);
        self::assertSame($this->step($existing, 'Fuzz registry parser and registration validation')['env']['CXX'], $run['env']['CXX']);
        self::assertArrayNotHasKey('continue-on-error', $run);
        self::assertArrayNotHasKey('continue-on-error', $job);
        self::assertArrayNotHasKey('if', $run);
        $output = 'var/test-runs/registry-nightly/${{ forgejo.run_id }}-${{ forgejo.run_attempt }}';
        self::assertSame($output, $run['env']['REGISTRY_QUALIFICATION_OUTPUT']);
        $upload = $this->step($job, 'Preserve nightly qualification evidence');
        $existingUpload = $this->step($this->workflow('ci.yaml')['jobs']['registry-container-quality'], 'Preserve packaged Registry inventories');
        self::assertSame($existingUpload['uses'], $upload['uses']);
        self::assertSame('always()', $upload['if']);
        self::assertSame($output, $upload['with']['path']);
        self::assertSame('registry-nightly-${{ forgejo.run_id }}-${{ forgejo.run_attempt }}', $upload['with']['name']);
        self::assertSame(14, $upload['with']['retention-days']);
        self::assertSame('error', $upload['with']['if-no-files-found']);
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryQualificationRejectsMissingStorageBeforeDownloading(string $mode): void
    {
        $process = $this->registryRecipe($mode, false);
        $process->run();

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('REGISTRY_FULL_DISK_ROOT', $process->getErrorOutput());
        self::assertStringNotContainsString('curl ', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.py', $this->registryCalls());
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryQualificationRejectsUnsafeStorageBeforeDownloading(string $mode): void
    {
        $process = $this->registryRecipe($mode, true, 43);
        $process->run();

        self::assertSame(43, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('validate_root', $this->registryCalls());
        self::assertStringNotContainsString('curl ', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.py', $this->registryCalls());
    }

    #[DataProvider('requiredRegistryModeProvider')]
    public function testRegistryStorageFailureStopsTheActualQualificationRecipe(string $mode): void
    {
        $process = $this->registryRecipe($mode, true);
        $process->run();

        self::assertSame(47, $process->getExitCode(), $process->getErrorOutput());
        $calls = $this->registryCalls();
        self::assertStringContainsString('validate_root', $calls);
        self::assertStringContainsString('test_storage_cleanup.py', $calls);
        self::assertStringContainsString('run_storage_contract.py', $calls);
        self::assertStringContainsString('--storage-root /run/baander-registry-full-disk', $calls);
        self::assertStringContainsString('--rqlited ', $calls);
        self::assertStringContainsString('--server ', $calls);
        self::assertStringContainsString('run_restore_contract.py', $calls);
        self::assertLessThan(strpos($calls, 'curl '), strpos($calls, 'validate_root'));
        self::assertLessThan(strpos($calls, 'run_storage_contract.py'), strpos($calls, 'run_restore_contract.py'));
        self::assertStringNotContainsString('run_load_smoke.py', $calls);
        self::assertStringNotContainsString('qualification passed', $process->getOutput());
    }

    #[DataProvider('registryModeProvider')]
    public function testRegistryStorageHelperFailureStopsEveryQualificationMode(string $mode): void
    {
        $process = $this->registryRecipe($mode, in_array($mode, ['release', 'sanitize'], true), storageHelperExit: 49);
        $process->run();

        self::assertSame(49, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('test_storage_cleanup.py', $this->registryCalls());
        self::assertStringNotContainsString('run_storage_contract.py', $this->registryCalls());
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
        string $job,
        string $stepName,
        string $command,
        string $matrixMode = 'release',
    ): void {
        $jobs = $this->workflow('ci.yaml')['jobs'];
        $step = $this->step($jobs[$job], $stepName);
        $tools = $this->directory . '/tools';
        (new Filesystem())->mkdir([$tools, $this->directory . '/scripts', $this->directory . '/sdk']);
        file_put_contents($this->directory . '/sdk/emsdk_env.sh', '');
        $log = $this->directory . '/calls';
        foreach (['yarn', 'corepack', 'docker', 'node', 'bash', 'python3'] as $tool) {
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
        $shell = str_replace('/tmp/baander-dsp-emsdk', $this->directory . '/sdk', $step['run']);
        $shell = str_replace('${{ matrix.mode }}', $matrixMode, $shell);
        $process = new Process(['/bin/bash', '-eo', 'pipefail', '-c', $shell], $this->directory, [
            'PATH' => $tools . ':' . getenv('PATH'),
            'CALL_LOG' => $log,
            'FAIL_COMMAND' => $command,
            'CI_IMAGE' => 'test-image',
            'CI_TEST_CONTAINER' => 'test-container',
            'CI_NETWORK' => 'test-network',
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

        foreach (['ci.yaml', 'frontend.yaml', 'dsp-analysis.yaml', 'registry-nightly.yaml'] as $file) {
            foreach ($this->workflow($file)['jobs'] as $jobName => $job) {
                $checkout = $this->step($job, 'Checkout')['run'];
                self::assertStringNotContainsString('${{ forgejo.ref }}', $checkout);
                self::assertStringContainsString('git rev-parse HEAD', $checkout);
                $workspace = $this->directory . '/' . $file . '-' . $jobName;
                (new Filesystem())->mkdir($workspace);
                $script = $this->renderCheckout($checkout, $remote, $sha);
                (new Process(['/bin/bash', '-e', '-c', $script], $workspace))->mustRun();
                self::assertSame($sha, trim($this->git(['rev-parse', 'HEAD'], $workspace)));
                self::assertSame('qualified', file_get_contents($workspace . '/version'));
            }
        }

        // Deliberately select the newer commit: the actual HEAD assertion must reject it.
        $checkout = $this->step($this->workflow('ci.yaml')['jobs']['build-and-push'], 'Checkout')['run'];
        $script = $this->renderCheckout($checkout, $remote, $sha);
        $script = str_replace(
            'git checkout -q --detach "' . $sha . '"',
            'git fetch -q origin "' . $advancedSha . '"' . "\n"
                . 'git checkout -q --detach "' . $advancedSha . '"',
            $script,
        );
        $workspace = $this->directory . '/mismatched-head';
        (new Filesystem())->mkdir($workspace);
        $process = new Process(['/bin/bash', '-e', '-c', $script], $workspace);
        $process->run();
        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($advancedSha, trim($this->git(['rev-parse', 'HEAD'], $workspace)));
    }

    public function testUnavailableEventCommitFailsCheckoutInsteadOfUsingBranchTip(): void
    {
        $remote = $this->directory . '/empty.git';
        $this->git(['init', '--bare', $remote]);
        $checkout = $this->step($this->workflow('ci.yaml')['jobs']['build-and-push'], 'Checkout')['run'];
        $workspace = $this->directory . '/checkout';
        (new Filesystem())->mkdir($workspace);
        $script = $this->renderCheckout($checkout, $remote, str_repeat('a', 40));
        $process = new Process(['/bin/bash', '-e', '-c', $script], $workspace);
        $process->run();
        self::assertFalse($process->isSuccessful());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3?: string}> */
    public static function failedQualificationProvider(): iterable
    {
        foreach (self::qualificationCommands() as $name => $case) {
            if ($name === 'registry') {
                foreach (['release', 'sanitize'] as $mode) {
                    yield $name . ' ' . $mode => [$case[0], $case[1], $case[2], $mode];
                }
                continue;
            }
            yield $name => $case;
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
            'backend runner' => ['quality-gate', 'Backend runner regressions', 'python3 -m unittest discover -s scripts/tests'],
            'backend isolation' => ['quality-gate', 'Backend stack isolation', 'scripts/test-backend-stack-isolation.py'],
            'Messenger without Swoole' => ['quality-gate', 'Messenger without Swoole', 'scripts/test-backend-container.sh'],
            'coverage integrity' => ['quality-gate', 'Extract coverage metrics', 'scripts/validate-backend-coverage.py'],
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
        return Yaml::parseFile(dirname(__DIR__, 5) . '/.forgejo/workflows/' . $name);
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

    private function renderCheckout(string $script, string $remote, string $sha): string
    {
        return strtr($script, [
            '${{ forgejo.event.repository.clone_url }}' => escapeshellarg($remote),
            '${{ secrets.GITHUB_TOKEN }}' => 'local-test-token',
            '${{ forgejo.sha }}' => $sha,
            '${{ forgejo.ref }}' => 'refs/heads/master',
        ]);
    }

    private function registryRecipe(string $mode, bool $configured, int $validationExit = 0, int $storageHelperExit = 0): Process
    {
        $tools = $this->directory . '/tools';
        (new Filesystem())->mkdir([$tools, $this->directory . '/scripts']);
        copy(dirname(__DIR__, 5) . '/scripts/test-registry.sh', $this->directory . '/scripts/test-registry.sh');
        $stub = <<<'SH'
#!/bin/bash
tool="${0##*/}"
printf '%s %s\n' "$tool" "$*" >> "$CALL_LOG"
case "$tool" in
    python3)
        case "$*" in
            *validate_root*) exit "$VALIDATION_EXIT" ;;
            *test_storage_cleanup.py*) exit "$STORAGE_HELPER_EXIT" ;;
            *run_storage_contract.py*) exit 47 ;;
        esac
        ;;
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
