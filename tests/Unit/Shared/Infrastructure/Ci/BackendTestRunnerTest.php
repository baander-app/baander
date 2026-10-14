<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 5) . '/scripts/backend-test-runner.php';

final class BackendTestRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-backend-runner-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function mismatches(): iterable
    {
        yield 'missing' => [['A::test#label'], []];
        yield 'duplicate' => [['A::test#label'], ['A::test#label', 'A::test#label']];
        yield 'unexpected' => [['A::test#label'], ['A::test#different']];
        yield 'empty' => [[], []];
    }

    /** @param list<string> $expected
     * @param list<string> $observed
     */
    #[DataProvider('mismatches')]
    public function testIncompleteIdentitySetsFail(array $expected, array $observed): void
    {
        $this->expectException(\RuntimeException::class);
        \BackendTestRunner::equalIdentities($expected, $observed);
    }

    public function testJunitPreservesDatasetLabels(): void
    {
        $path = $this->directory . '/report.xml';
        file_put_contents($path, '<testsuite><testcase classname="A" name="test with data set &quot;alpha # &quot;quoted&quot;&quot;"/><testcase classname="A" name="test with data set #0"/></testsuite>');
        self::assertSame(['A::test#alpha # "quoted"', 'A::test#0'], \BackendTestRunner::observed($path));
    }

    public function testFilteredDatasetRunsAndFinalizes(): void
    {
        $fixture = dirname(__DIR__, 5) . '/tests/Fixtures/BackendRunner/DatasetFixtureTest.php';
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit failOnRisky="true" failOnWarning="true"><testsuites><testsuite name="Fixtures"><file>' . $fixture . '</file></testsuite></testsuites></phpunit>');
        $this->runHelper(['discover', $this->directory, '-c', $configuration, '--filter=beta', '--fail-on-skipped']);
        $discovery = \BackendTestRunner::readJson($this->directory . '/discovery.json');
        self::assertCount(1, $discovery['files'][$fixture]);
        self::assertStringEndsWith('#beta', $discovery['files'][$fixture][0]);
        self::assertContains('--fail-on-skipped', $discovery['arguments']);
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', ['files' => [$fixture], 'processes' => 1, 'batch' => 1]);
        $this->runHelper(['run', $this->directory, 'ordinary']);
        $this->runHelper(['finalize', $this->directory]);
        self::assertSame(1, \BackendTestRunner::readJson($this->directory . '/finalize.json')['count']);
    }

    public function testSkippedIdentityIsRecordedWhileStrictFailureIsRetained(): void
    {
        $fixture = dirname(__DIR__, 5) . '/tests/Fixtures/BackendRunner/SkippedFixtureTest.php';
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit><testsuites><testsuite name="Fixtures"><file>' . $fixture . '</file></testsuite></testsuites></phpunit>');
        $this->runHelper(['discover', $this->directory, '-c', $configuration, '--fail-on-skipped']);
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', ['files' => [$fixture], 'processes' => 1, 'batch' => 1]);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 5) . '/scripts/backend-test-runner.php', 'run', $this->directory, 'ordinary']);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        $result = \BackendTestRunner::readJson($this->directory . '/ordinary.result.json');
        self::assertNotSame(0, $result['status']);
        self::assertSame(1, $result['count']);
    }

    public function testQuotedDatasetLabelsSurviveExecution(): void
    {
        $fixture = dirname(__DIR__, 5) . '/tests/Fixtures/BackendRunner/DatasetFixtureTest.php';
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit executionOrder="depends,random"><testsuites><testsuite name="Fixtures"><file>' . $fixture . '</file></testsuite></testsuites></phpunit>');
        $this->runHelper(['discover', $this->directory, '-c', $configuration, '--random-order-seed=12345']);
        $discovery = \BackendTestRunner::readJson($this->directory . '/discovery.json');
        self::assertSame(12345, $discovery['seed']);
        self::assertContains('--order-by=depends,random', $discovery['arguments']);
        self::assertContains('App\\Tests\\Fixtures\\BackendRunner\\DatasetFixtureTest::testLabels#alpha # "quoted"', $discovery['files'][$fixture]);
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', ['files' => [$fixture], 'processes' => 2, 'batch' => 1]);
        $this->runHelper(['run', $this->directory, 'ordinary']);
        $this->runHelper(['finalize', $this->directory]);
        self::assertSame(2, \BackendTestRunner::readJson($this->directory . '/finalize.json')['count']);
    }

    public function testDefaultRandomSeedIsPinnedDuringDiscovery(): void
    {
        $fixture = dirname(__DIR__, 5) . '/tests/Fixtures/BackendRunner/DatasetFixtureTest.php';
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit executionOrder="depends,random"><testsuites><testsuite name="Fixtures"><file>' . $fixture . '</file></testsuite></testsuites></phpunit>');
        $this->runHelper(['discover', $this->directory, '-c', $configuration]);
        $discovery = \BackendTestRunner::readJson($this->directory . '/discovery.json');
        self::assertContains('--random-order-seed=' . $discovery['seed'], $discovery['arguments']);
        self::assertContains('--order-by=depends,random', $discovery['arguments']);
    }

    public function testWorkerCrashFailsWithoutSuccessManifest(): void
    {
        $fixture = dirname(__DIR__, 5) . '/tests/Fixtures/BackendRunner/CrashFixtureTest.php';
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit><testsuites><testsuite name="Fixtures"><file>' . $fixture . '</file></testsuite></testsuites></phpunit>');
        $this->runHelper(['discover', $this->directory, '-c', $configuration]);
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', ['files' => [$fixture], 'processes' => 1, 'batch' => 1]);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 5) . '/scripts/backend-test-runner.php', 'run', $this->directory, 'ordinary']);
        $process->setTimeout(30);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertFileDoesNotExist($this->directory . '/ordinary.result.json');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArtifacts(): iterable
    {
        foreach (['missing report', 'stale result', 'changed report', 'missing lane', 'overlap', 'malformed manifest', 'coverage hash'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidArtifacts')]
    public function testFinalizationRejectsInvalidArtifacts(string $case): void
    {
        $fixture = '/fixture.php';
        \BackendTestRunner::writeJson($this->directory . '/discovery.json', ['files' => [$fixture => ['A::test']], 'coverage' => $case === 'coverage hash', 'runId' => 'current']);
        $manifest = ['files' => [$fixture], 'processes' => 1, 'batch' => 1];
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', $manifest);
        $report = $this->directory . '/ordinary.junit.xml';
        file_put_contents($report, '<testsuite><testcase classname="A" name="test"/></testsuite>');
        $result = ['status' => 0, 'runId' => $case === 'stale result' ? 'stale' : 'current', 'hashes' => ['junit' => hash_file('sha256', $report)]];
        if ($case === 'coverage hash') {
            file_put_contents($this->directory . '/ordinary.cov', 'corrupt');
            $result['hashes']['coverage'] = 'obsolete';
        }
        \BackendTestRunner::writeJson($this->directory . '/ordinary.result.json', $result);
        if ($case === 'missing report') {
            unlink($report);
        } elseif ($case === 'changed report') {
            file_put_contents($report, '<testsuite/>');
        } elseif ($case === 'missing lane') {
            unlink($this->directory . '/ordinary.json');
        } elseif ($case === 'overlap') {
            \BackendTestRunner::writeJson($this->directory . '/other.json', $manifest);
            copy($report, $this->directory . '/other.junit.xml');
            \BackendTestRunner::writeJson($this->directory . '/other.result.json', $result);
        } elseif ($case === 'malformed manifest') {
            file_put_contents($this->directory . '/ordinary.json', '{');
        }
        $this->expectException(\Throwable::class);
        \BackendTestRunner::finalize($this->directory);
    }

    public function testCorruptCoverageIsRejectedBeforePublishing(): void
    {
        $fixture = '/test.php';
        \BackendTestRunner::writeJson($this->directory . '/discovery.json', ['files' => [$fixture => ['A::test']], 'coverage' => true, 'runId' => 'current']);
        \BackendTestRunner::writeJson($this->directory . '/ordinary.json', ['files' => [$fixture], 'processes' => 1, 'batch' => 1]);
        file_put_contents($this->directory . '/ordinary.junit.xml', '<testsuite><testcase classname="A" name="test"/></testsuite>');
        file_put_contents($this->directory . '/ordinary.cov', 'corrupt');
        \BackendTestRunner::writeJson($this->directory . '/ordinary.result.json', ['status' => 0, 'runId' => 'current', 'hashes' => ['junit' => hash_file('sha256', $this->directory . '/ordinary.junit.xml'), 'coverage' => hash_file('sha256', $this->directory . '/ordinary.cov')]]);
        $this->expectException(\SebastianBergmann\CodeCoverage\Serialization\FileCouldNotBeReadException::class);
        \BackendTestRunner::finalize($this->directory);
    }

    public function testLaneConfigurationPreservesRelativePaths(): void
    {
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit bootstrap="bootstrap.php" cacheDirectory="cache" extensionsDirectory="extensions"><source baseline="baseline.xml"><include><directory>source</directory></include></source><php><includePath>includes</includePath></php><testsuites><testsuite name="original"><file>original.php</file></testsuite></testsuites></phpunit>');
        $target = $this->directory . '/lane.xml';
        \BackendTestRunner::laneConfiguration(['config' => $configuration], ['/absolute/test.php'], $target);
        $document = new \DOMDocument();
        self::assertTrue($document->load($target));
        foreach (['bootstrap' => 'bootstrap.php', 'cacheDirectory' => 'cache', 'extensionsDirectory' => 'extensions'] as $attribute => $path) {
            self::assertSame($this->directory . '/' . $path, $document->documentElement->getAttribute($attribute));
        }
        self::assertSame($this->directory . '/baseline.xml', $document->getElementsByTagName('source')->item(0)->getAttribute('baseline'));
        self::assertSame($this->directory . '/includes', $document->getElementsByTagName('includePath')->item(0)->textContent);
        self::assertSame($this->directory . '/source', $document->getElementsByTagName('directory')->item(0)->textContent);
        self::assertSame('/absolute/test.php', $document->getElementsByTagName('file')->item(0)->textContent);
    }

    public function testSuiteBootstrapConfigurationIsRejected(): void
    {
        $configuration = $this->directory . '/phpunit.xml';
        file_put_contents($configuration, '<phpunit><testsuites><testsuite name="original" bootstrap="bootstrap.php"><file>test.php</file></testsuite></testsuites></phpunit>');
        $process = new Process([PHP_BINARY, dirname(__DIR__, 5) . '/scripts/backend-test-runner.php', 'discover', $this->directory, '-c', $configuration]);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Suite bootstrap configuration is unsupported', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->directory . '/discovery.json');
    }

    public function testSerializedCoverageMergeKeepsUncoveredLinesAndExcludedFiles(): void
    {
        $source = <<<'PHP_SOURCE'
<?php
function firstCovered(): int
{
    return 1;
}
function secondCovered(): int
{
    return 2;
}
function remainsUncovered(): int
{
    return 3;
}
PHP_SOURCE;
        file_put_contents($this->directory . '/included.php', $source);
        file_put_contents($this->directory . '/excluded.php', '<?php function excluded(): int { return 4; }');
        $text = $this->directory . '/coverage/report.txt';
        $clover = $this->directory . '/coverage/clover.xml';
        \BackendTestRunner::writeJson($this->directory . '/discovery.json', ['files' => ['/first.php' => ['A::test'], '/second.php' => ['B::test']], 'coverage' => true, 'runId' => 'current', 'reports' => ['--coverage-text' => $text, '--coverage-clover' => $clover]]);
        foreach (['first' => ['A::test', 4], 'second' => ['B::test', 8]] as $lane => [$identity, $coveredLine]) {
            \BackendTestRunner::writeJson($this->directory . '/' . $lane . '.json', ['files' => ['/' . $lane . '.php'], 'processes' => 1, 'batch' => 1]);
            $report = $this->directory . '/' . $lane . '.junit.xml';
            file_put_contents($report, '<testsuite><testcase classname="' . $identity[0] . '" name="test"/></testsuite>');
            $data = new \SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData();
            $lines = [4 => [], 8 => [], 12 => []];
            $lines[$coveredLine] = [$identity];
            $data->setLineCoverage(['included.php' => $lines]);
            $serialized = [
                'buildInformation' => ['timestamp' => 'fixture', 'runtime' => ['name' => 'PHP', 'version' => PHP_VERSION, 'vendorUrl' => 'https://baander.app'], 'phpCodeCoverage' => ['version' => \SebastianBergmann\CodeCoverage\Version::id(), 'serializationFormat' => 1, 'driverInformation' => ['name' => 'fixture', 'version' => '1']]],
                'basePath' => $this->directory,
                'codeCoverage' => $data,
                'testResults' => [$identity => ['size' => 'small', 'status' => 'success', 'time' => 0.0]],
            ];
            $coverage = $this->directory . '/' . $lane . '.cov';
            file_put_contents($coverage, "<?php // phpunit/php-code-coverage serialization format 1\nreturn unserialize(base64_decode('" . base64_encode(serialize($serialized)) . "'));");
            \BackendTestRunner::writeJson($this->directory . '/' . $lane . '.result.json', ['status' => 0, 'runId' => 'current', 'hashes' => ['junit' => hash_file('sha256', $report), 'coverage' => hash_file('sha256', $coverage)]]);
        }
        \BackendTestRunner::finalize($this->directory);
        self::assertStringContainsString('Lines:   66.67% (2/3)', (string) file_get_contents($text));
        $document = new \DOMDocument();
        self::assertTrue($document->load($clover));
        $files = $document->getElementsByTagName('file');
        self::assertSame(1, $files->length);
        self::assertStringEndsWith('/included.php', $files->item(0)->getAttribute('name'));
        $counts = [];
        foreach ($document->getElementsByTagName('line') as $line) {
            $counts[(int) $line->getAttribute('num')] = (int) $line->getAttribute('count');
        }
        self::assertSame([4 => 1, 8 => 1, 12 => 0], $counts);
    }

    /** @param list<string> $arguments */
    private function runHelper(array $arguments): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 5) . '/scripts/backend-test-runner.php', ...$arguments]);
        $process->setTimeout(120);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
