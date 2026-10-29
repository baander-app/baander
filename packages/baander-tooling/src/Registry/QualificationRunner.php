<?php

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\SignalScope;
use Baander\Tooling\Runtime\Requirements;
use RuntimeException;
use Throwable;

final class QualificationRunner
{
    private string $work = '';
    private string $output = '';
    private string $mode = 'release';
    private string $nodes = '3';
    private string $fuzzRuns = '10000';
    private string $fuzzSeconds = '120';
    private string $revision = '';
    private int $commandNumber = 0;
    private EngineExecutor $runner;
    /** @var array<string,string> */
    private array $environment;
    private readonly ToolingConfiguration $configuration;
    private SignalScope $signals;

    public function __construct(private readonly string $root, private readonly ?StorageValidator $storageValidator = null)
    {
        $this->environment = getenv();
        $this->configuration = ToolingConfiguration::forRepository($root, $this->environment);
        $this->runner = new EngineExecutor(ExecutionContext::bounded($this->configuration->budgets->seconds('registryQualification'), $this->environment, $root));
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        Requirements::check();
        $this->signals = new SignalScope();
        $status = 0;
        try {
            $this->configure($arguments);
            $this->work = sys_get_temp_dir() . '/baander-registry-ci.' . bin2hex(random_bytes(8));
            $this->directory($this->work . '/tmp');
            $this->qualify();
            $this->write('Registry ' . $this->mode . " qualification passed.\n");
        } catch (Throwable $failure) {
            $status = $this->signals->receivedSignal() !== null
                ? 128 + $this->signals->receivedSignal()
                : ($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
            $this->write($failure->getMessage() . "\n", true);
        } finally {
            foreach ([fn() => $this->retain(), function (): void {
                if ($this->work !== '') {
                    Collector::remove($this->work);
                }
            }] as $cleanup) {
                try {
                    $cleanup();
                } catch (Throwable $failure) {
                    $status = $status ?: 1;
                    $this->write('Qualification cleanup failed: ' . $failure->getMessage() . "\n", true);
                }
            }
            $this->signals->dispatch();
            if ($status === 0 && $this->signals->receivedSignal() !== null) {
                $status = 128 + $this->signals->receivedSignal();
            }
            try {
                if ($this->output !== '' && $this->revision !== '') {
                    $this->metadata((string) $status);
                }
            } catch (Throwable $failure) {
                $status = $status ?: 1;
                $this->write('Cannot write qualification outcome: ' . $failure->getMessage() . "\n", true);
            } finally {
                $this->runner->close();
                $this->signals->close();
            }
        }
        return $status;
    }

    /** @param list<string> $arguments */
    private function configure(array $arguments): void
    {
        $this->mode = $arguments[0] ?? 'release';
        if (count($arguments) > 1 || !in_array($this->mode, ['release', 'sanitize', 'thread', 'fuzz'], true)) {
            throw new RuntimeException('Usage: test-registry.sh [release|sanitize|thread|fuzz]', 2);
        }
        $this->nodes = ($this->environment['REGISTRY_CLUSTER_NODES'] ?? '') ?: '3';
        $this->fuzzRuns = ($this->environment['REGISTRY_FUZZ_RUNS'] ?? '') ?: '10000';
        $this->fuzzSeconds = ($this->environment['REGISTRY_FUZZ_SECONDS'] ?? '') ?: '120';
        if (!in_array($this->nodes, ['3', '5'], true)) {
            throw new RuntimeException('REGISTRY_CLUSTER_NODES must be 3 or 5.', 2);
        }
        if ($this->mode === 'fuzz' && (!preg_match('/^[1-9][0-9]*$/D', $this->fuzzRuns) || !preg_match('/^[1-9][0-9]*$/D', $this->fuzzSeconds))) {
            throw new RuntimeException('REGISTRY_FUZZ_RUNS and REGISTRY_FUZZ_SECONDS must be positive integers.', 2);
        }
        $output = ($this->environment['REGISTRY_QUALIFICATION_OUTPUT'] ?? '') ?: '';
        if ($output !== '') {
            $this->directory($output);
            if (array_diff(scandir($output) ?: [], ['.', '..']) !== []) {
                throw new RuntimeException('REGISTRY_QUALIFICATION_OUTPUT must be an empty directory.', 2);
            }
            $this->output = realpath($output) ?: throw new RuntimeException('Cannot resolve qualification output.');
            $revision = $this->runner->run(new ProcessSpec(['git', '-C', $this->root, 'rev-parse', 'HEAD'], environment: $this->environment, timeout: 10));
            $this->revision = trim($revision->stdout);
            $this->metadata('running');
        }
    }

    private function qualify(): void
    {
        if (in_array($this->mode, ['release', 'sanitize'], true)) {
            $storage = ($this->environment['REGISTRY_FULL_DISK_ROOT'] ?? '') ?: '';
            if ($storage === '') {
                throw new RuntimeException('REGISTRY_FULL_DISK_ROOT must name an empty dedicated tmpfs (at most 128 MiB).', 2);
            }
            if ($this->storageValidator === null) {
                throw new RuntimeException('A bounded storage validator must be supplied by composition.', 2);
            }
            $this->storageValidator->validate($storage);
        }
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new RuntimeException('The verified rqlite qualification artifact requires Linux x86_64.', 2);
        }
        $thread = $this->mode === 'thread';
        $fuzz = $this->mode === 'fuzz';
        $cc = ($this->environment['CC'] ?? '') ?: 'clang';
        $cxx = ($this->environment['CXX'] ?? '') ?: 'clang++';
        $python = ($this->environment['REGISTRY_SUBJECT_PYTHON'] ?? '') ?: 'python3';
        foreach ([
            'cmake',
            'ninja',
            'c++',
            'make',
            'perl',
            'curl',
            'openssl',
            'sha256sum',
            'pkg-config',
            $python,
            ...($thread || $fuzz ? [$cc, $cxx] : []),
        ] as $command) {
            if (!$this->executable($command)) {
                throw new RuntimeException('Missing required executable: ' . $command, 2);
            }
        }
        $this->download(
            'https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz',
            'openssl.tar.gz',
            'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf',
        );
        $this->command(['tar', '-xzf', $this->work . '/openssl.tar.gz', '-C', $this->work]);
        $openssl = $this->work . '/openssl-3.5.3';
        foreach ([
            ['./Configure', '--prefix=' . $this->work . '/openssl', '--libdir=lib', 'no-shared', 'no-tests'],
            ['make', '-j2'],
            ['make', 'install_sw'],
        ] as $command) {
            $this->command($command, $openssl, log: 'openssl-build.log');
        }
        $this->download(
            'https://github.com/rqlite/rqlite/releases/download/v10.5.1/rqlite-v10.5.1-linux-amd64.tar.gz',
            'rqlite.tar.gz',
            'f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d',
        );
        $this->directory($this->work . '/rqlite');
        $this->command(['tar', '-xzf', $this->work . '/rqlite.tar.gz', '-C', $this->work . '/rqlite', '--strip-components=1']);
        $options = ['-DCMAKE_BUILD_TYPE=Release', '-DREGISTRY_SANITIZERS=OFF'];
        $environment = [];
        if (in_array($this->mode, ['sanitize', 'fuzz'], true)) {
            $options = ['-DCMAKE_BUILD_TYPE=Debug', '-DREGISTRY_SANITIZERS=ON', ...($fuzz ? ['-DREGISTRY_FUZZ=ON'] : [])];
            $environment = ['ASAN_OPTIONS' => 'detect_leaks=1:halt_on_error=1', 'UBSAN_OPTIONS' => 'halt_on_error=1:print_stacktrace=1'];
        } elseif ($thread) {
            $options = ['-DCMAKE_BUILD_TYPE=RelWithDebInfo', '-DREGISTRY_SANITIZERS=OFF', '-DREGISTRY_THREAD_SANITIZER=ON'];
            $environment = ['TSAN_OPTIONS' => 'halt_on_error=1:exitcode=66'];
        }
        if ($thread || $fuzz) {
            $environment += ['CC' => $cc, 'CXX' => $cxx];
        }
        $environment['PKG_CONFIG_PATH'] = $this->work . '/openssl/lib/pkgconfig'
            . ((($this->environment['PKG_CONFIG_PATH'] ?? '') ?: '') === '' ? '' : ':' . ($this->environment['PKG_CONFIG_PATH'] ?? ''));
        $build = $this->work . '/build';
        $this->command(
            [
                'cmake',
                '-S',
                $this->root . '/relay',
                '-B',
                $build,
                '-G',
                'Ninja',
                '-DOPENSSL_ROOT_DIR=' . $this->work . '/openssl',
                '-DOPENSSL_USE_STATIC_LIBS=TRUE',
                '-DREGISTRY_PHP_EXECUTABLE=' . PHP_BINARY,
                '-DREGISTRY_SUBJECT_PYTHON=' . $python,
                ...$options,
            ],
            environment: $environment,
        );
        $this->command(['cmake', '--build', $build, '--parallel', '2'], environment: $environment);
        $this->command(['ctest', '--test-dir', $build, '--output-on-failure', '--no-tests=error'], environment: $environment);
        $rqlited = $this->work . '/rqlite/rqlited';
        $server = $build . '/baander-registry';
        $this->contract('test_cluster_cleanup');
        $this->contract('test_storage_cleanup');
        $this->contract(
            'run_rqlite_contract',
            ['--rqlited', $rqlited, '--fixture', $build . '/registry_contract_fixture'],
            $environment,
        );
        $this->contract('run_transport_contract', ['--fixture', $build . '/registry_transport_fixture'], $environment);
        $this->contract('run_http_contract', ['--rqlited', $rqlited, '--server', $server], $environment);
        foreach (['run_cluster_contract' => 'cluster', 'run_restore_contract' => 'restore'] as $script => $directory) {
            $this->contract(
                $script,
                [
                    '--nodes',
                    $this->nodes,
                    ...($this->output === '' ? [] : ['--diagnostics', $this->output . '/' . $directory]),
                    '--rqlited',
                    $rqlited,
                    '--server',
                    $server,
                ],
                $environment,
            );
        }
        if (in_array($this->mode, ['release', 'sanitize'], true)) {
            $this->contract(
                'run_storage_contract',
                ['--rqlited', $rqlited, '--server', $server, '--storage-root', (string) ($this->environment['REGISTRY_FULL_DISK_ROOT'] ?? '')],
                $environment,
            );
        }
        if ($this->mode === 'release') {
            $this->contract('run_load_smoke', ['--rqlited', $rqlited, '--server', $server], $environment);
        }
        if ($fuzz) {
            $this->fuzz($build, $cxx, $environment);
        }
    }

    /** @param array<string,string> $environment */
    private function fuzz(string $build, string $cxx, array $environment): void
    {
        $this->directory($this->work . '/corpus');
        $this->directory($this->work . '/artifacts');
        foreach (glob($this->root . '/relay/tests/parser_corpus/*') ?: [] as $seed) {
            copy($seed, $this->work . '/corpus/' . basename($seed));
        }
        $result = $this->command(
            [
                $build . '/registry_parser_fuzz',
                $this->work . '/corpus',
                '-runs=' . $this->fuzzRuns,
                '-max_total_time=' . $this->fuzzSeconds,
                '-seed=1',
                '-max_len=65537',
                '-timeout=10',
                '-rss_limit_mb=1024',
                '-artifact_prefix=' . $this->work . '/artifacts/',
            ],
            environment: $environment,
            log: 'fuzz.log',
            timeout: $this->configuration->budgets->seconds('registryFuzz'),
            requireZero: false,
        );
        if ($result->exitCode !== 0) {
            foreach (glob($this->work . '/artifacts/*') ?: [] as $artifact) {
                if (is_file($artifact)) {
                    $this->write('Failing input (base64): ' . (file_get_contents($artifact) |> base64_encode(...)) . "\n", true);
                }
            }
            throw new RuntimeException('Parser fuzzer failed.', $result->exitCode);
        }
        $source = <<<'CPP'
#include <cstddef>
#include <cstdint>
extern "C" int LLVMFuzzerTestOneInput(const std::uint8_t *, std::size_t) {
    auto *bytes = new char[1];
    delete[] bytes;
    return *static_cast<volatile char *>(bytes);
}
CPP;
        file_put_contents($this->work . '/fuzz-negative.cpp', $source);
        $this->command(
            [
                $cxx,
                '-O0',
                '-g',
                '-fsanitize=fuzzer,address,undefined',
                $this->work . '/fuzz-negative.cpp',
                '-o',
                $this->work . '/fuzz-negative',
            ],
            environment: $environment,
        );
        $control = $this->command(
            [$this->work . '/fuzz-negative', '-runs=1', '-artifact_prefix=' . $this->work . '/artifacts/'],
            environment: $environment,
            log: 'fuzz-negative.log',
            timeout: $this->configuration->budgets->seconds('registryFuzz'),
            requireZero: false,
        );
        if (
            $control->exitCode === 0
            || !str_contains((string) file_get_contents($this->work . '/fuzz-negative.log'), 'AddressSanitizer: heap-use-after-free')
        ) {
            throw new RuntimeException('Fuzzer negative control did not report the expected memory error.');
        }
        $this->write("Parser fuzzer ASan negative control passed.\n");
    }

    private function download(string $url, string $name, string $digest): void
    {
        $path = $this->work . '/' . $name;
        $this->command([
            'curl',
            '--fail',
            '--silent',
            '--show-error',
            '--location',
            '--retry',
            '3',
            '--max-time',
            '180',
            $url,
            '-o',
            $path,
        ]);
        $this->command(['sha256sum', '--check', '--status'], input: $digest . '  ' . $path . "\n");
    }

    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    private function contract(string $name, array $arguments = [], array $environment = []): void
    {
        $this->command([PHP_BINARY, $this->root . '/relay/tests/' . $name . '.php', ...$arguments], environment: $environment);
    }

    /** @param non-empty-list<string> $argv
     * @param array<string,string> $environment
     */
    private function command(
        array $argv,
        ?string $cwd = null,
        array $environment = [],
        ?string $log = null,
        bool $requireZero = true,
        ?float $timeout = null,
        ?string $input = null,
    ): ProcessResult {
        $this->signals->dispatch();
        if ($this->signals->receivedSignal() !== null) {
            throw new RuntimeException('Qualification interrupted.', 128 + $this->signals->receivedSignal());
        }
        $logPath = $this->work . '/' . ($log ?? 'command-' . ++$this->commandNumber . '.log');
        $this->write('Running: ' . implode(' ', $argv) . "\n");
        try {
            $result = $this->runner->run(new ProcessSpec(
                $argv,
                cwd: $cwd ?? $this->root,
                environment: array_replace($this->environment, ['TMPDIR' => $this->work . '/tmp'], $environment),
                timeout: $timeout ?? $this->configuration->budgets->seconds('registryQualification'),
                stdin: $input,
                logPath: $logPath,
                processGroup: true,
                appendLog: $log !== null,
                terminationGrace: 5,
            ), new CompletionPolicy($requireZero ? [0] : null));
        } finally {
            $stream = is_file($logPath) ? fopen($logPath, 'rb') : false;
            if ($stream !== false) {
                while (!feof($stream)) {
                    $this->write(fread($stream, 65536) ?: '');
                }
                fclose($stream);
            }
        }
        if ($this->signals->receivedSignal() !== null) {
            throw new RuntimeException('Qualification interrupted.', 128 + $this->signals->receivedSignal());
        }
        return $result;
    }

    private function retain(): void
    {
        if ($this->output === '' || $this->work === '') {
            return;
        }
        foreach (['openssl-build.log', 'fuzz.log', 'fuzz-negative.log'] as $name) {
            if (is_file($this->work . '/' . $name)) {
                copy($this->work . '/' . $name, $this->output . '/' . $name);
            }
        }
        if (is_dir($this->work . '/artifacts')) {
            $this->directory($this->output . '/fuzz-artifacts');
            foreach (glob($this->work . '/artifacts/*') ?: [] as $path) {
                if (is_file($path)) {
                    copy($path, $this->output . '/fuzz-artifacts/' . basename($path));
                }
            }
        }
    }

    private function metadata(string $status): void
    {
        $content = [
            'revision=' . $this->revision,
            'mode=' . $this->mode,
            'cluster_nodes=' . $this->nodes,
            'fuzz_runs=' . $this->fuzzRuns,
            'fuzz_seconds=' . $this->fuzzSeconds,
            'exit_status=' . $status,
        ] |> (static fn(array $lines): string => implode("\n", $lines) . "\n");
        file_put_contents(
            $this->output . '/metadata.env',
            $content,
        );
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? STDERR : STDOUT, $message);
        if ($this->output !== '') {
            file_put_contents($this->output . '/console.log', $message, FILE_APPEND);
        }
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }

    private function executable(string $name): bool
    {
        if (str_contains($name, '/')) {
            return is_executable($name);
        }
        return array_any(
            explode(PATH_SEPARATOR, ($this->environment['PATH'] ?? '') ?: ''),
            static fn(string $directory): bool => is_executable($directory . '/' . $name),
        );
    }
}
