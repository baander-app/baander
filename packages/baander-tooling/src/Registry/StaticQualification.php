<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Tooling\Registry;

use Baander\Registry\StaticAnalysis\Diagnostics;
use Baander\Registry\StaticAnalysis\Pins;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\RegistryTests\Runtime;
use RuntimeException;

final class StaticQualification
{
    private const string CHECKS = '-*,clang-analyzer-*'
        . ',bugprone-use-after-move,bugprone-dangling-handle,bugprone-infinite-loop'
        . ',bugprone-misplaced-widening-cast,bugprone-sizeof-expression'
        . ',bugprone-suspicious-memset-usage,bugprone-undefined-memory-manipulation';

    /** @param non-empty-list<string> $command */
    private static function logged(array $command, string $log, ?string $cwd = null, float $timeout = 1800): int
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            $command,
            cwd: $cwd,
            environment: getenv(),
            timeout: $timeout,
            logPath: $log,
            processGroup: true,
            appendLog: true,
        ));
        Runtime::requireThat(
            !$result->cancelled && !$result->timedOut,
            'Registry static command interrupted or timed out: ' . json_encode($command),
        );
        return $result->exitCode;
    }

    /** @param non-empty-list<string> $command */
    private static function checked(array $command, float $timeout = 30): string
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            $command,
            environment: getenv(),
            timeout: $timeout,
            processGroup: true,
        ));
        Runtime::requireThat(
            $result->exitCode === 0 && !$result->cancelled && !$result->timedOut,
            'Registry static command failed: ' . json_encode($command) . "\n" . $result->stderr,
        );
        return $result->stdout;
    }

    /** @param non-empty-list<string> $command */
    private static function build(array $command, string $log, ?string $cwd = null): void
    {
        $status = self::logged($command, $log, $cwd);
        if ($status !== 0) {
            self::tail($log, 80);
            throw new RuntimeException('Registry static build failed: ' . json_encode($command));
        }
    }

    private static function tail(string $log, int $lines): void
    {
        $content = $log
            |> Pins::read(...)
            |> (static fn(string $value): array => explode("\n", $value))
            |> (static fn(array $values): array => array_slice($values, -$lines))
            |> (static fn(array $values): string => implode("\n", $values));
        fwrite(STDERR, $content . "\n");
    }

    private static function download(string $url, string $target, string $digest): void
    {
        self::checked(
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
        Runtime::requireThat(hash_file('sha256', $target) === $digest, 'Upstream archive or notice digest changed: ' . $url);
    }

    private static function same(string $retained, string $upstream): void
    {
        Runtime::requireThat(Pins::read($retained) === Pins::read($upstream), 'Retained notice differs from upstream: ' . $retained);
    }

    public static function run(string $root): void
    {
        Runtime::requireThat(extension_loaded('yaml'), 'Registry static analysis requires ext-yaml.');
        putenv('CC=clang-19');
        putenv('CXX=clang++-19');
        foreach (['cmake', 'ninja', 'clang-19', 'clang++-19', 'clang-tidy-19', 'make', 'perl', 'curl', 'tar', 'pkg-config'] as $command) {
            $found = false;
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                $found = $found || is_executable($directory . '/' . $command);
            }
            Runtime::requireThat($found, 'Missing required executable: ' . $command);
        }
        Runtime::requireThat(
            preg_match('/LLVM version 19\./', self::checked(['clang-tidy-19', '--version'])) === 1,
            'Registry static qualification requires clang-tidy 19.',
        );
        $work = Runtime::temporaryDirectory('baander-registry-static-');
        $previousPkg = getenv('PKG_CONFIG_PATH');
        try {
            self::download(
                'https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz',
                $work . '/openssl.tar.gz',
                'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf',
            );
            self::checked(['tar', '-xzf', $work . '/openssl.tar.gz', '-C', $work], timeout: 180);
            self::same($root . '/relay/third_party/openssl-LICENSE.txt', $work . '/openssl-3.5.3/LICENSE.txt');
            $log = $work . '/openssl-build.log';
            self::build(
                ['./Configure', '--prefix=' . $work . '/openssl', '--libdir=lib', 'no-shared', 'no-tests'],
                $log,
                $work . '/openssl-3.5.3',
            );
            self::build(['make', '-j2'], $log, $work . '/openssl-3.5.3');
            self::build(['make', 'install_sw'], $log, $work . '/openssl-3.5.3');
            putenv('PKG_CONFIG_PATH=' . $work . '/openssl/lib/pkgconfig' . ($previousPkg ? ':' . $previousPkg : ''));
            self::build(
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
                self::same($root . '/relay/third_party/' . $name . '-LICENSE.txt', $work . '/build/_deps/' . $path);
            }
            self::download(
                'https://raw.githubusercontent.com/rqlite/rqlite/v10.5.1/LICENSE',
                $work . '/rqlite-LICENSE',
                'eebaac1ed2a0deece5e8ea39c679bd2c8813aa8da3ec95cdda953b95ce349629',
            );
            self::same($root . '/relay/third_party/rqlite-LICENSE.txt', $work . '/rqlite-LICENSE');
            echo "All seven reviewed direct dependency notices match pinned upstream contents.\n";
            foreach (glob($root . '/relay/cpp/*.cpp') ?: [] as $source) {
                echo 'Analyzing ' . $source . "\n";
                $export = $work . '/diagnostics.yaml';
                if (is_file($export)) {
                    unlink($export);
                }
                $log = $work . '/clang-tidy.log';
                $status = self::logged(
                    [
                        'clang-tidy-19',
                        $source,
                        '-p',
                        $work . '/build',
                        '--export-fixes=' . $export,
                        '--config=' . self::config(true),
                    ],
                    $log,
                );
                try {
                    Diagnostics::check($status, $export, $work . '/build/_deps');
                } catch (RuntimeException $failure) {
                    self::tail($log, 120);
                    throw $failure;
                }
            }
            self::controls($work);
            echo "Registry production static analysis and direct dependency license qualification passed.\n";
        } finally {
            putenv($previousPkg === false ? 'PKG_CONFIG_PATH' : 'PKG_CONFIG_PATH=' . $previousPkg);
            Runtime::removeTree($work);
        }
    }

    private static function config(bool $headers = false): string
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

    private static function rejected(int $status, string $export, string $dependencies, string $expected): void
    {
        ob_start();
        try {
            try {
                Diagnostics::check($status, $export, $dependencies);
            } catch (RuntimeException $failure) {
                Runtime::requireThat(
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
        $status = self::logged(
            ['clang-tidy-19', $negative, '--export-fixes=' . $export, '--config=' . self::config(), '--', '-std=c++20'],
            $log,
        );
        $output = Pins::read($log);
        Runtime::requireThat(
            $status !== 0
                && str_contains($output, 'clang-analyzer-core.NullDereference')
                && str_contains($output, 'clang-analyzer-core.CallAndMessage'),
            'Static analyzer controls did not report both expected memory bugs. ' . $output,
        );
        self::rejected($status, $export, $work . '/build/_deps', 'Blocking diagnostic: clang-analyzer-core.');
        $compiler = $work . '/compiler-negative.cpp';
        file_put_contents($compiler, 'int broken() { return missing_symbol; }' . "\n");
        $export = $work . '/compiler-diagnostics.yaml';
        $status = self::logged(
            ['clang-tidy-19', $compiler, '--export-fixes=' . $export, '--config=' . self::config(), '--', '-std=c++20'],
            $work . '/compiler-negative.log',
        );
        Diagnostics::mixCompilerControl($export, $work . '/build/_deps');
        self::rejected($status, $export, $work . '/build/_deps', 'Blocking diagnostic: clang-diagnostic-error');
    }
}
