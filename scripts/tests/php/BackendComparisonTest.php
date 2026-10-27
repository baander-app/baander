<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Backend\Comparison;
use Baander\Tooling\Development\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

final class BackendComparisonTest extends TestCase
{
    private const string SOURCE = '/var/www/html/src/Probe.php';
    private string $directory;
    private Comparison $comparison;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-comparison-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->comparison = new Comparison(dirname(__DIR__, 3));
        $this->fixture($this->directory . '/reference');
        $this->fixture($this->directory . '/candidate');
    }

    protected function tearDown(): void
    {
        new Runner()->run(['rm', '-rf', '--', $this->directory]);
    }

    private function fixture(string $directory): void
    {
        mkdir($directory . '/run', 0700, true);
        mkdir($directory . '/reports', 0700);
        $this->json(
            $directory . '/run/discovery.json',
            [
                'runId' => 'current',
                'coverage' => true,
                'seed' => 123,
                'files' => ['tests/Unit/ProbeTest.php' => ['ProbeTest::testProbe']],
            ],
        );
        $this->json($directory . '/timing.json', ['status' => 0, 'elapsed' => 2]);
        $this->lane($directory);
        $this->clover($directory);
    }

    /** @param array<string,mixed> $data */
    private function json(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param array<positive-int,?list<string>> $lines */
    private function lane(string $directory, string $name = 'ordinary', array $lines = [5 => ['ProbeTest::testProbe']]): void
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('name')->willReturn('fixture');
        $driver->method('version')->willReturn('1');
        $coverage = new CodeCoverage($driver, new Filter());
        $data = new ProcessedCodeCoverageData();
        $data->setLineCoverage([self::SOURCE => $lines]);
        $coverage->setData($data);
        $path = $directory . '/run/' . $name . '.cov';
        new Serializer()->serialize($path, $coverage);
        $this->json(
            $directory . '/run/' . $name . '.result.json',
            [
                'status' => 0,
                'runId' => 'current',
                'identities' => ['ProbeTest::testProbe'],
                'hashes' => ['coverage' => hash_file('sha256', $path)],
            ],
        );
    }

    private function clover(
        string $directory,
        int $statements = 1,
        int $covered = 1,
        string $entries = '<line type="method" num="5" count="1"/>',
    ): void {
        file_put_contents(
            $directory . '/reports/clover.xml',
            '<coverage><project><file name="' . self::SOURCE . '">' . $entries . '</file>'
            . '<metrics files="1" classes="1" methods="1" statements="' . $statements . '" coveredstatements="' . $covered . '"/></project></coverage>',
        );
    }

    public function testRealSerializedCoveragePreservesOneLineMethodIdentity(): void
    {
        [$metrics, $lines, $covered] = $this->comparison->coverage($this->directory . '/reference');
        self::assertSame(1, $metrics['statements']);
        self::assertSame([json_encode([self::SOURCE, 5])], $lines);
        self::assertSame($lines, $covered);
    }

    public function testNonexecutableHeaderIsExcludedFromRealSerializedCoverage(): void
    {
        $directory = $this->directory . '/reference';
        $this->lane($directory, lines: [10 => null, 12 => ['ProbeTest::testProbe']]);
        $this->clover(
            $directory,
            entries: '<line type="method" num="10" count="1"/><line type="stmt" num="12" count="1"/>',
        );
        [, $lines, $covered] = $this->comparison->coverage($directory);
        self::assertSame([json_encode([self::SOURCE, 12])], $lines);
        self::assertSame($lines, $covered);
    }

    public function testAllValidatedLanesAreMerged(): void
    {
        $directory = $this->directory . '/reference';
        $this->lane($directory, 'stack-1', [5 => [], 8 => []]);
        $this->clover($directory, 2, 1);
        [, $lines, $covered] = $this->comparison->coverage($directory);
        self::assertSame([json_encode([self::SOURCE, 5]), json_encode([self::SOURCE, 8])], $lines);
        self::assertSame([json_encode([self::SOURCE, 5])], $covered);
    }

    /** @return iterable<string,array{string}> */
    public static function damagedCoverage(): iterable
    {
        foreach ([
            'missing',
            'changed',
            'corrupt',
            'stale',
            'failed',
            'zero denominator',
            'wrong denominator',
            'wrong covered count',
        ] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('damagedCoverage')]
    public function testDamagedCoverageIsRejected(string $mode): void
    {
        $directory = $this->directory . '/reference';
        $path = $directory . '/run/ordinary.cov';
        switch ($mode) {
            case 'missing': unlink($path);
                break;
            case 'changed': file_put_contents($path, 'changed');
                break;
            case 'corrupt':
                file_put_contents($path, 'invalid serialization');
                $this->json(
                    $directory . '/run/ordinary.result.json',
                    ['status' => 0, 'runId' => 'current', 'hashes' => ['coverage' => hash_file('sha256', $path)]],
                );
                break;
            case 'stale': $this->json($directory . '/run/ordinary.result.json', ['status' => 0, 'runId' => 'previous']);
                break;
            case 'failed': $this->json($directory . '/run/ordinary.result.json', ['status' => 1, 'runId' => 'current']);
                break;
            case 'zero denominator': $this->clover($directory, 0);
                break;
            case 'wrong denominator': $this->clover($directory, 2);
                break;
            case 'wrong covered count': $this->clover($directory, 1, 0);
                break;
        }
        $this->expectException(RuntimeException::class);
        $this->comparison->coverage($directory);
    }

    public function testExactLineDifferencesAreRejectedEvenWhenCloverCountsAgree(): void
    {
        $this->lane($this->directory . '/candidate', lines: [6 => ['ProbeTest::testProbe']]);
        $this->expectExceptionMessage('executable_difference');
        $this->comparison->compare($this->directory . '/reference', $this->directory . '/candidate');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidIdentities(): iterable
    {
        foreach (['empty', 'duplicate selected', 'duplicate completed', 'missing completed', 'stale', 'failed'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('invalidIdentities')]
    public function testIncompleteOrInvalidIdentitiesAreRejected(string $mode): void
    {
        $directory = $this->directory . '/reference';
        $identities = ['ProbeTest::testProbe'];
        if ($mode === 'empty' || $mode === 'duplicate selected') {
            $this->json(
                $directory . '/run/discovery.json',
                [
                    'runId' => 'current',
                    'coverage' => true,
                    'seed' => 123,
                    'files' => $mode === 'empty' ? [] : ['tests/Unit/ProbeTest.php' => [...$identities, ...$identities]],
                ],
            );
        } else {
            $this->json(
                $directory . '/run/ordinary.result.json',
                [
                    'status' => $mode === 'failed' ? 1 : 0,
                    'runId' => $mode === 'stale' ? 'previous' : 'current',
                    'identities' => match ($mode) {
                        'missing completed' => [],
                        'duplicate completed' => [...$identities, ...$identities],
                        default => $identities,
                    },
                ],
            );
        }
        $this->expectException(RuntimeException::class);
        Comparison::identities($directory);
    }

    public function testEquivalentCompletedRunsReportDenominatorsAndSpeedup(): void
    {
        $this->json($this->directory . '/reference/timing.json', ['status' => 0, 'elapsed' => 4]);
        $result = $this->comparison->compare($this->directory . '/reference', $this->directory . '/candidate');
        self::assertSame(1, $result['tests']);
        self::assertSame(123, $result['seed']);
        self::assertTrue($result['coverage']);
        self::assertSame(['files' => 1, 'classes' => 1, 'methods' => 1, 'statements' => 1], $result['denominators']);
        self::assertSame(1, $result['covered_lines']);
        self::assertSame(2.0, $result['speedup']);
    }

    public function testSeedAndCoverageModeMustMatch(): void
    {
        foreach (['seed' => 124, 'coverage' => false] as $key => $value) {
            $discovery = [
                'runId' => 'current',
                'coverage' => true,
                'seed' => 123,
                'files' => ['test' => ['ProbeTest::testProbe']],
            ];
            $discovery[$key] = $value;
            $this->json($this->directory . '/candidate/run/discovery.json', $discovery);
            try {
                $this->comparison->compare($this->directory . '/reference', $this->directory . '/candidate');
                self::fail('Expected differing run configuration rejection.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('seed or coverage mode differ', $error->getMessage());
            }
        }
    }

    public function testFailedTimingIsRejected(): void
    {
        $this->json($this->directory . '/candidate/timing.json', ['status' => 1, 'elapsed' => 2]);
        $this->expectExceptionMessage('candidate run did not complete');
        $this->comparison->compare($this->directory . '/reference', $this->directory . '/candidate');
    }

    public function testCliComparesRealRunsAndReportsUsageErrors(): void
    {
        $entrypoint = dirname(__DIR__, 2) . '/compare-backend-runs.php';
        $runner = new Runner();
        $result = $runner->run([PHP_BINARY, $entrypoint, $this->directory . '/reference', $this->directory . '/candidate']);
        self::assertSame(1, json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)['tests']);
        self::assertSame(2, $runner->run([PHP_BINARY, $entrypoint], check: false)->exitCode);
    }
}
