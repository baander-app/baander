<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\ControllerPayload;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use PHPUnit\Framework\TestCase;

final class ControllerPayloadTest extends TestCase
{
    private string $root;
    private ControllerPayload $payload;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-controller-generation-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->copy(dirname(__DIR__, 3) . '/packages/baander-tooling', $this->root . '/source/packages/baander-tooling');
        $this->copy(dirname(__DIR__, 2) . '/vendor', $this->root . '/source/scripts/vendor');
        $this->copy(dirname(__DIR__, 3) . '/docker/dev/systemd', $this->root . '/source/docker/dev/systemd');
        copy(dirname(__DIR__, 2) . '/bootstrap.php', $this->root . '/source/scripts/bootstrap.php');
        file_put_contents($this->root . '/source/packages/baander-tooling/src/Development/RetainedGenerationProbe.php', '<?php namespace Baander\\Tooling\\Development; final class RetainedGenerationProbe { public const VALUE = "old-generation"; }');
        file_put_contents($this->root . '/source/packages/baander-tooling/src/Development/RetainedGenerationDestructorProbe.php', '<?php namespace Baander\Tooling\Development; final class RetainedGenerationDestructorProbe { public const VALUE = "old-destructor"; }');
        file_put_contents($this->root . '/source/scripts/dev-environment.php', <<<'CODE'
<?php
require __DIR__ . '/bootstrap.php';
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Development\RetainedGenerationProbe;
if (($argv[1] ?? '') === 'shutdown') {
    register_shutdown_function(static function (): void {
        echo "shutdown-ready\n";
        fflush(STDOUT);
        fgets(STDIN);
        echo RetainedGenerationProbe::VALUE . "\n";
    });
    $GLOBALS['controllerShutdownProbe'] = new class {
        public function __destruct()
        {
            echo "destructor-ready\n";
            fflush(STDOUT);
            fgets(STDIN);
            echo Baander\Tooling\Development\RetainedGenerationDestructorProbe::VALUE;
        }
    };
} elseif (($argv[1] ?? '') === 'wait') {

    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    echo RetainedGenerationProbe::VALUE;
} else {
    $layout = ApplicationLayout::load();
    echo json_encode(['retained' => class_exists(RetainedGenerationProbe::class), 'layout' => $layout->version, 'defaults' => count(ToolingConfiguration::resolve()->images->toArray()) > 0], JSON_THROW_ON_ERROR);
}
CODE);
        $this->payload = new ControllerPayload($this->root . '/state');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testOldInvocationLoadsRemovedClassAfterNewSelectionUntilItExits(): void
    {
        $old = $this->payload->publish($this->root . '/source');
        $child = proc_open([PHP_BINARY, $this->payload->launcher(), 'wait'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            unlink($this->root . '/source/packages/baander-tooling/src/Development/RetainedGenerationProbe.php');
            $new = $this->payload->publish($this->root . '/source');
            self::assertNotSame($old, $new);
            self::assertDirectoryExists($this->generation($old));
            rename($this->root . '/source', $this->root . '/source-offline');
            $result = new EngineExecutor(ExecutionContext::bounded(30, Environment::clean()))->run(new ProcessSpec([PHP_BINARY, $this->payload->launcher()]));
            self::assertSame(['retained' => false, 'layout' => 1, 'defaults' => true], json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR));
            fwrite($pipes[0], "finish\n");
            fclose($pipes[0]);
            self::assertSame('old-generation', stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($child));
        }
        $this->payload->retireUnused();
        self::assertDirectoryDoesNotExist($this->generation($old));
        self::assertDirectoryExists($this->generation($new));
    }

    public function testLeaseSurvivesShutdownCallbacksAndDestructors(): void
    {
        $old = $this->payload->publish($this->root . '/source');
        $child = proc_open([PHP_BINARY, $this->payload->launcher(), 'shutdown'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        try {
            self::assertSame("shutdown-ready\n", fgets($pipes[1]));
            unlink($this->root . '/source/packages/baander-tooling/src/Development/RetainedGenerationProbe.php');
            unlink($this->root . '/source/packages/baander-tooling/src/Development/RetainedGenerationDestructorProbe.php');
            $this->payload->publish($this->root . '/source');
            self::assertDirectoryExists($this->generation($old));
            fwrite($pipes[0], "callback\n");
            self::assertSame("old-generation\n", fgets($pipes[1]));
            self::assertSame("destructor-ready\n", fgets($pipes[1]));
            $this->payload->publish($this->root . '/source');
            self::assertDirectoryExists($this->generation($old));
            fwrite($pipes[0], "destructor\n");
            fclose($pipes[0]);
            self::assertSame('old-destructor', stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($child));
        }
        $this->payload->retireUnused();
        self::assertDirectoryDoesNotExist($this->generation($old));
    }

    public function testUncertainSelectorPreservesRenamedCompleteGeneration(): void
    {
        $old = $this->payload->publish($this->root . '/source');
        unlink($this->root . '/state/controller/selected.json');
        mkdir($this->root . '/state/controller/selected.json');
        try {
            $this->payload->publish($this->root . '/source');
            self::fail('A directory selector was accepted.');
        } catch (\RuntimeException) {
            $generations = array_values(array_diff(scandir($this->root . '/state/controller/generations'), ['.', '..']));
            self::assertCount(2, $generations);
            foreach ($generations as $generation) {
                self::assertFileExists($this->generation($generation) . '/scripts/dev-environment.php');
            }
            rmdir($this->root . '/state/controller/selected.json');
            AtomicFile::writeJson($this->root . '/state/controller/selected.json', ['generation' => $old]);
            $this->payload->retireUnused();
            self::assertSame([$old], array_values(array_diff(scandir($this->root . '/state/controller/generations'), ['.', '..'])));
        }
    }

    public function testStagingFailurePreservesSelectedRunnablePayload(): void
    {
        $old = $this->payload->publish($this->root . '/source');
        unlink($this->root . '/source/scripts/vendor/autoload.php');
        try {
            $this->payload->publish($this->root . '/source');
            self::fail('Incomplete generation accepted.');
        } catch (\RuntimeException) {
            self::assertSame($old, $this->payload->selectedGeneration());
            self::assertSame(0, new EngineExecutor(ExecutionContext::bounded(30, Environment::clean()))->run(new ProcessSpec([PHP_BINARY, $this->payload->launcher()]))->exitCode);
        }
    }

    public function testLauncherHoldsSharedLeaseBeforeLoadingGeneration(): void
    {
        $old = $this->payload->publish($this->root . '/source');
        $child = proc_open([PHP_BINARY, $this->payload->launcher(), 'wait'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            $lease = FileLock::tryAcquire($this->root . '/state/controller/leases/' . $old . '.lock');
            self::assertNull($lease);
            fwrite($pipes[0], "finish\n");
            fclose($pipes[0]);
            self::assertSame('old-generation', stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($child));
        }
    }

    public function testPublicationLockCoversSelectionAndLeaseAcquisition(): void
    {
        $id = $this->payload->publish($this->root . '/source');
        $child = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/controller-publication-gate.php', $this->payload->launcher()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        try {
            self::assertSame("waiting\n", fgets($pipes[1]));
            $publication = FileLock::acquire($this->root . '/state/controller/publication.lock');
            try {
                fwrite($pipes[0], "select\n");
                $read = [$pipes[1]];
                $write = $except = [];
                self::assertSame(0, stream_select($read, $write, $except, 0, 50000));
                $unused = FileLock::tryAcquire($this->root . '/state/controller/leases/' . $id . '.lock');
                self::assertInstanceOf(FileLock::class, $unused);
                $unused->release();
            } finally {
                $publication->release();
            }
            self::assertSame("ready\n", fgets($pipes[1]));
            self::assertNull(FileLock::tryAcquire($this->root . '/state/controller/leases/' . $id . '.lock'));
            fwrite($pipes[0], "finish\n");
            fclose($pipes[0]);
            self::assertSame('old-generation', stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($child));
        }
    }

    public function testDrainWaitsForOldReadersAndPreventsIncompatiblePublication(): void
    {
        $this->payload->publish($this->root . '/source');
        $reader = proc_open([PHP_BINARY, $this->payload->launcher(), 'wait'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $readerPipes);
        self::assertIsResource($reader);
        self::assertSame("ready\n", fgets($readerPipes[1]));
        $selected = $this->payload->publish($this->root . '/source');
        $drainer = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/controller-drain-readers.php', $this->root . '/state'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $drainPipes);
        self::assertIsResource($drainer);
        try {
            $read = [$drainPipes[1]];
            $write = $except = [];
            self::assertSame(0, stream_select($read, $write, $except, 0, 50000));
            fwrite($readerPipes[0], "finish\n");
            fclose($readerPipes[0]);
            self::assertSame('old-generation', stream_get_contents($readerPipes[1]));
            self::assertSame("drained\n", fgets($drainPipes[1]));
            try {
                $this->payload->publish($this->root . '/source');
                self::fail('Publication crossed the retained conversion barrier.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('draining controller readers', $error->getMessage());
                self::assertSame($selected, $this->payload->selectedGeneration());
            }
            fwrite($drainPipes[0], "finish\n");
            fclose($drainPipes[0]);
            self::assertSame('', stream_get_contents($drainPipes[1]));
            self::assertSame('', stream_get_contents($drainPipes[2]));
        } finally {
            foreach (array_merge($readerPipes, $drainPipes) as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($reader));
            self::assertSame(0, proc_close($drainer));
        }
        self::assertNotSame($selected, $this->payload->publish($this->root . '/source'));
    }

    public function testAbsentInstalledControllerHasNoReadersToDrain(): void
    {
        $barrier = $this->payload->drainReaders();
        try {
            self::assertNull($this->payload->selectedGeneration());
            self::assertNull(FileLock::tryAcquire($this->root . '/state/controller/compatibility.lock', shared: true));
        } finally {
            $barrier->release();
        }
    }

    public function testUnleasedLegacyPayloadBlocksConversionAndRemainsIntact(): void
    {
        $this->payload->publish($this->root . '/source');
        mkdir($this->root . '/state/controller/scripts');
        file_put_contents($this->root . '/state/controller/scripts/dev-environment.py', 'retained legacy code');
        try {
            $barrier = $this->payload->drainReaders();
            $barrier->release();
            self::fail('Unleased readers were treated as drained.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unleased readers', $error->getMessage());
            self::assertSame('retained legacy code', file_get_contents($this->root . '/state/controller/scripts/dev-environment.py'));
        }
    }

    public function testHostileSelectorNeverLoadsCodeOutsideGenerations(): void
    {
        $this->payload->publish($this->root . '/source');
        AtomicFile::writeJson($this->root . '/state/controller/selected.json', ['generation' => '../../source']);
        $result = new EngineExecutor(ExecutionContext::bounded(30, Environment::clean()))->run(new ProcessSpec([PHP_BINARY, $this->payload->launcher()]), new CompletionPolicy(null));
        self::assertNotSame(0, $result->exitCode);
        self::assertStringContainsString('Invalid controller generation selector', $result->stderr);
    }

    private function generation(string $id): string
    {
        return $this->root . '/state/controller/generations/' . $id;
    }

    private function copy(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        foreach (array_diff(scandir($source), ['.', '..']) as $name) {
            $from = $source . '/' . $name;
            $to = $destination . '/' . $name;
            if (is_link($from)) {
                symlink('../../../packages/baander-tooling', $to);
            } elseif (is_dir($from)) {
                $this->copy($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $name) {
                $this->remove($path . '/' . $name);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
