<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Inventory\ApplicationInventory;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Inventory\Json;
use PHPUnit\Framework\TestCase;

final class RetainBuildEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-retain-evidence-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }
    protected function tearDown(): void
    {
        Collector::remove($this->root);
    }

    /** @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function process(array $arguments): array
    {
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** @param list<string> $arguments */
    private function helper(array $arguments, bool $succeeds = true): string
    {
        [$status, , $stderr] = $this->process([PHP_BINARY, dirname(__DIR__, 2) . '/retain-build-evidence.php', ...$arguments]);
        self::assertSame($succeeds, $status === 0, $stderr);
        return $stderr;
    }

    /** @return array{string, string} */
    private function collect(bool $notices = true): array
    {
        mkdir($this->root . '/native');
        if ($notices) {
            file_put_contents($this->root . '/native/LICENSE', 'upstream terms');
            mkdir($this->root . '/native/vendor');
            file_put_contents($this->root . '/native/vendor/NOTICE.txt', 'embedded attribution');
        }
        file_put_contents(
            $this->root . '/native/package.xml',
            '<package><version><release>2.3.4</release></version></package>',
        );
        $artifact = $this->root . '/native.so';
        file_put_contents($artifact, 'compiled bytes');
        $output = $this->root . '/native-evidence';
        $this->helper([
            'collect',
            '--output',
            $output,
            '--id',
            'native',
            '--name',
            'native',
            '--source-dir',
            $this->root . '/native',
            '--package-xml',
            $this->root . '/native/package.xml',
            '--artifact',
            $artifact,
            '--artifact',
            $this->root . '/absent-*.so',
        ]);
        return [$output, $artifact];
    }

    /** @return array<string, mixed> */
    private function component(string $output): array
    {
        return Json::read($output . '/components.json')['components'][0];
    }
    private function retained(string $output, string $path): string
    {
        return $output . '/' . substr($path, strlen('/usr/share/baander-build-evidence/'));
    }

    public function testRecursiveNoticesAndFinalArtifactHashes(): void
    {
        [$input, $artifact] = $this->collect();
        file_put_contents($artifact, 'final stripped bytes');
        $output = $this->root . '/final';
        $this->helper(['finalize', '--output', $output, '--input', $input]);
        $component = $this->component($output);
        self::assertSame('2.3.4', $component['version']);
        self::assertCount(2, $component['notices']);
        foreach ($component['notices'] as $notice) {
            self::assertFileExists($this->retained($output, $notice));
        }
        self::assertSame([['path' => $artifact, 'sha256' => hash('sha256', 'final stripped bytes')]], $component['artifacts']);
        self::assertSame('unreviewed', $component['embedded_dependencies']);
        self::assertCount(1, $component['metadata']['unmatched_artifact_patterns']);
    }

    public function testMissingNoticesRemainExplicit(): void
    {
        [$input] = $this->collect(false);
        $output = $this->root . '/final';
        $this->helper(['finalize', '--output', $output, '--input', $input]);
        $component = $this->component($output);
        self::assertSame([], $component['notices']);
        self::assertStringContainsString('No upstream notices', $component['metadata']['notices_gap']);
        self::assertNull($component['source']['url']);
    }

    public function testCollectAppendsComponentsInOneStageDirectory(): void
    {
        [$output, $artifact] = $this->collect();
        $this->helper([
            'collect',
            '--output',
            $output,
            '--id',
            'second',
            '--name',
            'second',
            '--source-dir',
            $this->root . '/native',
            '--artifact',
            $artifact,
        ]);
        self::assertSame(['native', 'second'], array_column(Json::read($output . '/components.json')['components'], 'id'));
    }

    public function testMissingExactArtifactFailsFinalization(): void
    {
        [$input, $artifact] = $this->collect();
        unlink($artifact);
        $stderr = $this->helper(['finalize', '--output', $this->root . '/final', '--input', $input], false);
        self::assertStringContainsString('Expected final artifact is missing', $stderr);
        self::assertStringContainsString($artifact, $stderr);
    }

    public function testDuplicateComponentsFail(): void
    {
        [$input] = $this->collect();
        self::assertStringContainsString(
            'Duplicate component',
            $this->helper(['finalize', '--output', $this->root . '/final', '--input', $input, '--input', $input], false),
        );
    }

    public function testToolVersionsFromRetainedLiteralsAndOwnNoticeGap(): void
    {
        $cases = [
            ['composer', 'src/Composer/Composer.php', "public const VERSION = '2.10.3';", '2.10.3'],
            ['pie', 'src/Util/PieVersion.php', 'private const PIE_VERSION = "1.5.2";', '1.5.2'],
            ['composer', 'src/Composer/Composer.php', "public const VERSION = '@package_version@';", null],
            ['pie', 'src/Other.php', "private const PIE_VERSION = '1.5.2';", null],
        ];
        foreach ($cases as $index => [$identity, $sourcePath, $literal, $expected]) {
            $archive = $this->root . '/' . $index . '.phar';
            $marker = $this->root . '/' . $index . '-executed';
            $source = "<?php\n" . $literal . "\nfile_put_contents(" . var_export($marker, true) . ", 'executed');";
            [$status, , $stderr] = $this->process([
                PHP_BINARY,
                '-d',
                'phar.readonly=0',
                __DIR__ . '/Fixtures/inventory-phar.php',
                $archive,
                $sourcePath,
                $source,
                $marker,
                $identity . '.phar',
                'version',
            ]);
            self::assertSame(0, $status, $stderr);
            $output = $this->root . '/' . $index . '-evidence';
            $this->helper(['phar', '--output', $output, '--id', $identity, '--name', $identity, '--archive', $archive]);
            $component = $this->component($output);
            self::assertSame($expected, $component['version']);
            self::assertFileDoesNotExist($marker);
            self::assertCount(1, $component['notices']);
            self::assertSame([], $component['metadata']['own_notices']);
            self::assertStringContainsString('tool itself', $component['metadata']['own_notices_gap']);
            self::assertSame($expected !== null, $component['metadata']['version_gap'] === null);
            if ($sourcePath !== 'src/Other.php') {
                self::assertSame($source, file_get_contents($this->retained($output, $component['metadata']['version_source'])));
            }
        }
    }

    public function testPharIndexAndMetadataAreReadWithoutExecution(): void
    {
        $archive = $this->root . '/tool.phar';
        $marker = $this->root . '/executed';
        [$status, , $stderr] = $this->process([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            __DIR__ . '/Fixtures/inventory-phar.php',
            $archive,
            'vendor/embedded/COPYING',
            'embedded terms',
            $marker,
            'tool.phar',
            'metadata',
        ]);
        self::assertSame(0, $status, $stderr);
        $output = $this->root . '/phar-evidence';
        $this->helper([
            'phar',
            '--output',
            $output,
            '--id',
            'tool',
            '--name',
            'tool',
            '--archive',
            $archive,
            '--artifact',
            $archive,
        ]);
        self::assertFileDoesNotExist($marker);
        $component = $this->component($output);
        self::assertCount(3, $component['notices']);
        self::assertCount(1, $component['metadata']['retained_metadata']);
        self::assertStringContainsString(
            'metadata executed',
            (string) file_get_contents($this->retained($output, $component['metadata']['retained_metadata'][0])),
        );
        self::assertSame(ApplicationInventory::fileHash($archive), $component['source']['sha256']);
    }
}
