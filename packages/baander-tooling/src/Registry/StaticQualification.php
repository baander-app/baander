<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Tooling\Registry\StaticAnalysis\Diagnostics;
use Baander\Tooling\Registry\StaticAnalysis\Pins;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Configuration\ToolingConfiguration;
use RuntimeException;

final class StaticQualification
{
    private const string CHECKS = '-*,clang-analyzer-*'
        . ',bugprone-use-after-move,bugprone-dangling-handle,bugprone-infinite-loop'
        . ',bugprone-misplaced-widening-cast,bugprone-sizeof-expression'
        . ',bugprone-suspicious-memset-usage,bugprone-undefined-memory-manipulation';

    private EngineExecutor $executor;
    /** @var array<string,string> */
    private array $environment;

    public function __construct(string $root)
    {
        $this->environment = array_replace(getenv(), ['CC' => 'clang-19', 'CXX' => 'clang++-19']);
        $configuration = ToolingConfiguration::forRepository($root, $this->environment);
        $this->executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('registryStatic'), $this->environment));
    }

    /** @param non-empty-list<string> $command */
    private function logged(array $command, string $log, ?string $cwd = null, float $timeout = 1800): int
    {
        $result = $this->executor->run(new ProcessSpec(
            $command,
            cwd: $cwd,
            environment: $this->environment,
            timeout: $timeout,
            logPath: $log,
            processGroup: true,
            appendLog: true,
        ), new CompletionPolicy(null));
        return $result->exitCode;
    }

    /** @param non-empty-list<string> $command */
    private function checked(array $command, float $timeout = 30): string
    {
        $result = $this->executor->run(new ProcessSpec(
            $command,
            environment: $this->environment,
            timeout: $timeout,
            processGroup: true,
        ));
        return $result->stdout;
    }

    /** @param non-empty-list<string> $command */
    private function build(array $command, string $log, ?string $cwd = null): void
    {
        $status = $this->logged($command, $log, $cwd);
        if ($status !== 0) {
            $this->tail($log, 80);
            throw new RuntimeException('Registry static build failed: ' . json_encode($command), $status);
        }
    }

    private function tail(string $log, int $lines): void
    {
        $content = $log
            |> Pins::read(...)
            |> (static fn(string $value): array => explode("\n", $value))
            |> (static fn(array $values): array => array_slice($values, -$lines))
            |> (static fn(array $values): string => implode("\n", $values));
        fwrite(STDERR, $content . "\n");
    }

    private function download(string $url, string $target, string $digest): void
    {
        $this->checked(
            [
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
                $target,
            ],
            timeout: 600,
        );
        Support::requireThat(hash_file('sha256', $target) === $digest, 'Upstream archive or notice digest changed: ' . $url);
    }

    private function same(string $retained, string $upstream): void
    {
        Support::requireThat(Pins::read($retained) === Pins::read($upstream), 'Retained notice differs from upstream: ' . $retained);
    }

    public static function run(string $root): void
    {
        $qualification = new self($root);
        try {
            $qualification->execute($root);
        } finally {
            $qualification->executor->close();
        }
    }

    private function execute(string $root): void
    {
        Support::requireThat(extension_loaded('yaml'), 'Registry static analysis requires ext-yaml.');
        foreach (['cmake', 'ninja', 'clang-19', 'clang++-19', 'clang-tidy-19', 'make', 'perl', 'curl', 'tar', 'pkg-config'] as $command) {
            $found = false;
            foreach (explode(PATH_SEPARATOR, $this->environment['PATH'] ?? '') as $directory) {
                $found = $found || is_executable($directory . '/' . $command);
            }
            Support::requireThat($found, 'Missing required executable: ' . $command);
        }
        Support::requireThat(
            preg_match('/LLVM version 19\./', $this->checked(['clang-tidy-19', '--version'])) === 1,
            'Registry static qualification requires clang-tidy 19.',
        );
        $work = Support::temporaryDirectory('baander-registry-static-');
        $previousPkg = $this->environment['PKG_CONFIG_PATH'] ?? '';
        $primary = null;
        try {
            $this->download(
                'https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz',
                $work . '/openssl.tar.gz',
                'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf',
            );
            $this->checked(['tar', '-xzf', $work . '/openssl.tar.gz', '-C', $work], timeout: 180);
            $this->same($root . '/relay/third_party/openssl-LICENSE.txt', $work . '/openssl-3.5.3/LICENSE.txt');
            $log = $work . '/openssl-build.log';
            $this->build(
                ['./Configure', '--prefix=' . $work . '/openssl', '--libdir=lib', 'no-shared', 'no-tests'],
                $log,
                $work . '/openssl-3.5.3',
            );
            $this->build(['make', '-j2'], $log, $work . '/openssl-3.5.3');
            $this->build(['make', 'install_sw'], $log, $work . '/openssl-3.5.3');
            $this->environment['PKG_CONFIG_PATH'] = $work . '/openssl/lib/pkgconfig' . ($previousPkg ? ':' . $previousPkg : '');
            $this->build(
                [
                    'cmake',
                    '-S',
                    $root . '/relay',
                    '-B',
                    $work . '/build',
                    '-G',
                    'Ninja',
                    '-DCMAKE_BUILD_TYPE=Debug',
                    '-DCMAKE_EXPORT_COMPILE_COMMANDS=ON',
                    '-DOPENSSL_ROOT_DIR=' . $work . '/openssl',
                    '-DOPENSSL_USE_STATIC_LIBS=TRUE',
                    '-DREGISTRY_SANITIZERS=OFF',
                    '-DREGISTRY_THREAD_SANITIZER=OFF',
                    '-DREGISTRY_FUZZ=OFF',
                ],
                $work . '/cmake.log',
            );
            foreach ([
                'boost' => 'boost_headers-src/LICENSE_1_0.txt',
                'json' => 'json-src/LICENSE.MIT',
                'googletest' => 'googletest-src/LICENSE',
            ] as $name => $path) {
                $this->same($root . '/relay/third_party/' . $name . '-LICENSE.txt', $work . '/build/_deps/' . $path);
            }
            $this->download(
                'https://raw.githubusercontent.com/rqlite/rqlite/v10.5.1/LICENSE',
                $work . '/rqlite-LICENSE',
                'eebaac1ed2a0deece5e8ea39c679bd2c8813aa8da3ec95cdda953b95ce349629',
            );
            $this->same($root . '/relay/third_party/rqlite-LICENSE.txt', $work . '/rqlite-LICENSE');
            echo "All seven reviewed direct dependency notices match pinned upstream contents.\n";
            foreach (glob($root . '/relay/cpp/*.cpp') ?: [] as $source) {
                echo 'Analyzing ' . $source . "\n";
                $export = $work . '/diagnostics.yaml';
                if (is_file($export)) {
                    unlink($export);
                }
                $log = $work . '/clang-tidy.log';
                $status = $this->logged(
                    [
                        'clang-tidy-19',
                        $source,
                        '-p',
                        $work . '/build',
                        '--export-fixes=' . $export,
                        '--config=' . $this->config(true),
                    ],
                    $log,
                );
                try {
                    Diagnostics::check($status, $export, $work . '/build/_deps');
                } catch (RuntimeException $failure) {
                    $this->tail($log, 120);
                    throw $failure;
                }
            }
            $this->executeControls($work);
            echo "Registry production static analysis and direct dependency license qualification passed.\n";
        } catch (\Throwable $failure) {
            $primary = $failure;
            throw $failure;
        } finally {
            try {
                Support::removeTree($work);
            } catch (\Throwable $cleanup) {
                throw new RuntimeException(
                    ($primary !== null ? $primary->getMessage() . '; ' : '') . 'Registry static cleanup failed: ' . $cleanup->getMessage(),
                    $primary !== null ? ($primary->getCode() ?: 1) : 1,
                    $primary ?? $cleanup,
                );
            }
        }
    }

    private function config(bool $headers = false): string
    {
        return json_encode(
            [
                'Checks' => self::CHECKS,
                'WarningsAsErrors' => '*',
                ...($headers ? ['HeaderFilterRegex' => '.*/relay/include/.*', 'SystemHeaders' => false] : []),
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    private function rejected(int $status, string $export, string $dependencies, string $expected): void
    {
        ob_start();
        try {
            try {
                Diagnostics::check($status, $export, $dependencies);
            } catch (RuntimeException $failure) {
                Support::requireThat(
                    str_contains($failure->getMessage(), $expected),
                    'Scope control failed without expected rejection: ' . $failure->getMessage(),
                );
                return;
            }
            throw new RuntimeException('Diagnostic scope control unexpectedly passed.');
        } finally {
            ob_end_clean();
        }
    }

    public static function controls(string $work): void
    {
        $qualification = new self(dirname(__DIR__, 4));
        try {
            $qualification->executeControls($work);
        } finally {
            $qualification->executor->close();
        }
    }

    private function executeControls(string $work): void
    {
        $negative = $work . '/analyzer-negative.cpp';
        file_put_contents(
            $negative,
            <<<'CPP'
struct Object { int value; int get() { return value; } };
int uninitialized_object() {
    Object *object;
    return object->get();
}
int null_pointer() {
    int *pointer = nullptr;
    return *pointer;
}
CPP,
        );
        $export = $work . '/negative-diagnostics.yaml';
        $log = $work . '/analyzer-negative.log';
        $status = $this->logged(
            ['clang-tidy-19', $negative, '--export-fixes=' . $export, '--config=' . $this->config(), '--', '-std=c++20'],
            $log,
        );
        $output = Pins::read($log);
        Support::requireThat(
            $status !== 0
                && str_contains($output, 'clang-analyzer-core.NullDereference')
                && str_contains($output, 'clang-analyzer-core.CallAndMessage'),
            'Static analyzer controls did not report both expected memory bugs. ' . $output,
        );
        $this->rejected($status, $export, $work . '/build/_deps', 'Blocking diagnostic: clang-analyzer-core.');
        $compiler = $work . '/compiler-negative.cpp';
        file_put_contents($compiler, 'int broken() { return missing_symbol; }' . "\n");
        $export = $work . '/compiler-diagnostics.yaml';
        $status = $this->logged(
            ['clang-tidy-19', $compiler, '--export-fixes=' . $export, '--config=' . $this->config(), '--', '-std=c++20'],
            $work . '/compiler-negative.log',
        );
        Diagnostics::mixCompilerControl($export, $work . '/build/_deps');
        $this->rejected($status, $export, $work . '/build/_deps', 'Blocking diagnostic: clang-diagnostic-error');
    }
}
