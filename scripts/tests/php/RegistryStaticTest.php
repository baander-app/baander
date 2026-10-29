<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Registry\StaticAnalysis\Diagnostics;
use Baander\Tooling\Registry\StaticAnalysis\Pins;
use Baander\Tooling\Registry\StaticQualification;
use Baander\RegistryTests\Process;
use Baander\RegistryTests\Runtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/relay/vendor/autoload.php';

final class RegistryStaticTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = Runtime::temporaryDirectory('registry-static-test-');
        mkdir($this->work . '/deps');
        file_put_contents($this->work . '/deps/source.hpp', '// dependency');
        file_put_contents($this->work . '/source.cpp', '// first party');
    }

    protected function tearDown(): void
    {
        Runtime::removeTree($this->work);
    }

    public function testMissingCompilerDoesNotChangeCallerCompilerEnvironment(): void
    {
        $names = ['CC', 'CXX', 'PATH'];
        $before = array_combine($names, array_map(getenv(...), $names));
        putenv('CC=caller-cc');
        putenv('CXX=caller-cxx');
        putenv('PATH=' . $this->work);
        try {
            try {
                StaticQualification::run($this->work);
                self::fail('Missing executable must fail qualification.');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('Missing required executable:', $failure->getMessage());
            }
            self::assertSame('caller-cc', getenv('CC'));
            self::assertSame('caller-cxx', getenv('CXX'));
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testDependencyAnalyzerWarningsAreExcludedByPrimaryLocation(): void
    {
        $export = $this->writeDiagnostic('clang-analyzer-core.CallAndMessage', $this->work . '/deps/source.hpp');
        $this->expectOutputRegex('/Outside production source scope:/');
        Diagnostics::check(1, $export, $this->work . '/deps');
    }

    /** @return iterable<string,array{string,string}> */
    public static function blockingDiagnostics(): iterable
    {
        yield 'first party analyzer' => ['clang-analyzer-core.NullDereference', '/source.cpp'];
        yield 'compiler error inside dependency' => ['clang-diagnostic-error', '/deps/source.hpp'];
        yield 'unknown location' => ['bugprone-use-after-move', '/deps/missing.hpp'];
        yield 'sibling prefix' => ['clang-analyzer-core.CallAndMessage', '/deps-other/source.hpp'];
        yield 'missing location' => ['clang-analyzer-core.CallAndMessage', ''];
    }

    #[DataProvider('blockingDiagnostics')]
    public function testPrimaryLocationsAndCompilerErrorsRemainBlocking(string $name, string $file): void
    {
        mkdir($this->work . '/deps-other');
        file_put_contents($this->work . '/deps-other/source.hpp', '// sibling');
        $export = $this->writeDiagnostic($name, $file === '' ? '' : $this->work . $file);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocking diagnostic: ' . $name);
        Diagnostics::check(1, $export, $this->work . '/deps');
    }

    public function testSymlinksCannotHideFirstPartyLocations(): void
    {
        symlink($this->work . '/source.cpp', $this->work . '/deps/link.hpp');
        $export = $this->writeDiagnostic('bugprone-use-after-move', $this->work . '/deps/link.hpp');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocking diagnostic:');
        Diagnostics::check(1, $export, $this->work . '/deps');
    }

    /** @return iterable<array{int,bool,string}> */
    public static function incompleteAnalysis(): iterable
    {
        yield [2, false, 'without completing analysis'];
        yield [1, false, 'without exporting diagnostics'];
        yield [1, true, 'without structured diagnostics'];
    }

    #[DataProvider('incompleteAnalysis')]
    public function testIncompleteAnalysisFailsClosed(int $status, bool $exported, string $message): void
    {
        $export = $this->work . '/diagnostics.yaml';
        if ($exported) {
            file_put_contents($export, "Diagnostics: []\n");
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        Diagnostics::check($status, $export, $this->work . '/deps');
    }

    public function testRealAnalyzerAndCompilerNegativeControls(): void
    {
        if (!is_executable('/usr/bin/clang-tidy-19')) {
            self::markTestSkipped('clang-tidy-19 unavailable.');
        }
        mkdir($this->work . '/build');
        mkdir($this->work . '/build/_deps');
        mkdir($this->work . '/build/_deps/boost_headers-src/boost/asio/impl', 0777, true);
        file_put_contents($this->work . '/build/_deps/boost_headers-src/boost/asio/impl/awaitable.hpp', '// dependency');
        StaticQualification::controls($this->work);
        self::assertFileExists($this->work . '/compiler-diagnostics.yaml');
    }

    public function testRepositoryPinsAndNotices(): void
    {
        $this->expectOutputRegex('/Reviewed direct dependency pins/');
        Pins::check(dirname(__DIR__, 3));
    }

    public function testTerminationStopsDownloadDescendantsAndPreservesSignalStatus(): void
    {
        $bin = $this->work . '/bin';
        mkdir($bin);
        $fixture = '#!' . PHP_BINARY . "\n<?php\n" . <<<'PHP'
if (basename($argv[0]) === 'clang-tidy-19') {
    echo "LLVM version 19.1.7\n";
    exit(0);
}
if (basename($argv[0]) === 'curl') {
    $child = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
    file_put_contents(getenv('REGISTRY_STATIC_CHILD'), (string) proc_get_status($child)['pid']);
    sleep(30);
}
PHP;
        $this->tools($bin, $fixture);
        $pidFile = $this->work . '/child.pid';
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/test-registry-static.php'],
            env: ['PATH' => $bin . ':' . getenv('PATH'), 'REGISTRY_STATIC_CHILD' => $pidFile],
        );
        try {
            $until = Runtime::now() + 5;
            while (!is_file($pidFile) && $process->poll() === null && Runtime::now() < $until) {
                usleep(5000);
            }
            self::assertFileExists($pidFile, $process->stderr);
            $process->signal(15);
            $code = $process->wait(5);
        } finally {
            $process->close(false);
        }
        self::assertSame(143, $code, $process->stderr);
        $stat = @file_get_contents('/proc/' . trim(Pins::read($pidFile)) . '/stat');
        self::assertTrue($stat === false || preg_match('/\) Z /', $stat) === 1, 'The download descendant must have exited.');
    }

    public function testDownloadFailurePreservesItsCommandStatus(): void
    {
        $bin = $this->work . '/bin';
        mkdir($bin);
        $fixture = '#!' . PHP_BINARY . "\n<?php\n" . <<<'PHP'
if (basename($argv[0]) === 'clang-tidy-19') {
    echo "LLVM version 19.1.7\n";
    exit(0);
}
if (basename($argv[0]) === 'curl') {
    fwrite(STDERR, "injected static download failure\n");
    exit(19);
}
PHP;
        $this->tools($bin, $fixture);
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/test-registry-static.php'],
            env: ['PATH' => $bin . ':' . getenv('PATH')],
        );
        try {
            self::assertSame(19, $process->wait(5), $process->stderr);
            self::assertStringContainsString('injected static download failure', $process->stderr);
        } finally {
            $process->close(false);
        }
    }

    private function tools(string $bin, string $fixture): void
    {
        file_put_contents($bin . '/tool', $fixture);
        chmod($bin . '/tool', 0755);
        foreach ([
            'cmake',
            'ninja',
            'clang-19',
            'clang++-19',
            'clang-tidy-19',
            'make',
            'perl',
            'curl',
            'tar',
            'pkg-config',
        ] as $name) {
            symlink($bin . '/tool', $bin . '/' . $name);
        }
    }

    /** @return iterable<array{string,string,string}> */
    public static function changedPins(): iterable
    {
        yield [
            'relay/CMakeLists.txt',
            "\nFetchContent_Declare(unreviewed URL https://baander.app/archive URL_HASH SHA256=" . str_repeat('a', 64) . ")\n",
            'dependency inventory changed',
        ];
        yield ['relay/third_party/age-LICENSE.txt', 'changed notice', 'notice changed: age'];
        yield ['relay/cpp/fixture.cpp', 'int main() {}', 'Missing first-party Apache-2.0'];
    }

    #[DataProvider('changedPins')]
    public function testDependencyAndNoticeChangesFailClosed(string $path, string $addition, string $message): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            'relay/CMakeLists.txt',
            'relay/docker/Dockerfile',
            'relay/docker/rqlite.Dockerfile',
            'relay/docker/backup.Dockerfile',
            'relay/third_party/inventory/go/components.json',
            ...(glob($root . '/relay/third_party/*-LICENSE.txt') ?: []),
        ] as $file) {
            $relative = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
            $target = $this->work . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy($root . '/' . $relative, $target);
        }
        foreach (['cpp', 'include', 'tests'] as $directory) {
            mkdir($this->work . '/relay/' . $directory);
        }
        file_put_contents($this->work . '/' . $path, $addition, FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        Pins::check($this->work);
    }

    private function writeDiagnostic(string $name, string $filename): string
    {
        $export = $this->work . '/diagnostics.yaml';
        file_put_contents(
            $export,
            yaml_emit(['Diagnostics' => [[
                'DiagnosticName' => $name,
                'DiagnosticMessage' => ['FilePath' => $filename],
                'Notes' => [['FilePath' => $this->work . '/source.cpp']],
            ]]]),
        );
        return $export;
    }
}
