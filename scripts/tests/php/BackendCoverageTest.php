<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Backend\Coverage;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessSpec};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendCoverageTest extends TestCase
{
    private const string TEXT = <<<'TEXT'
        Code Coverage Report:
          Classes: 50.00% (1/2)
          Methods: 50.00% (1/2)
          Lines:   50.00% (2/4)

        Baander\Covered
          Methods: 100.00% (1/1)  Lines: 100.00% (2/2)
        TEXT;
    private const string XML = <<<'XML'
        <coverage><project><file name="/var/www/html/src/Covered.php">
        <class name="Covered"><metrics methods="1" coveredmethods="1" statements="2" coveredstatements="2" conditionals="0" coveredconditionals="0"/></class>
        <class name="Uncovered"><metrics methods="1" coveredmethods="0" statements="2" coveredstatements="0" conditionals="0" coveredconditionals="0"/></class>
        <metrics classes="2" methods="2" coveredmethods="1" statements="4" coveredstatements="2"/>
        </file><metrics files="1" classes="2" methods="2" coveredmethods="1" statements="4" coveredstatements="2"/></project></coverage>
        XML;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-coverage-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->write();
    }

    protected function tearDown(): void
    {
        Collector::remove($this->directory);
    }

    private function write(string $text = self::TEXT, string $xml = self::XML): void
    {
        file_put_contents($this->directory . '/report.txt', $text);
        file_put_contents($this->directory . '/clover.xml', $xml);
    }

    public function testValidReportsPublishAllMetricsAndIgnoreDetailedClassRows(): void
    {
        $expected = ['classes' => 50.0, 'methods' => 50.0, 'lines' => 50.0];
        self::assertSame($expected, Coverage::validate($this->directory));
        self::assertEquals(
            $expected,
            json_decode((string) file_get_contents($this->directory . '/metrics.json'), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame([], glob($this->directory . '/.metrics*'));
    }

    public function testPercentagesWithoutTextCountsAreSupported(): void
    {
        $this->write(str_replace([' (1/2)', ' (2/4)'], '', self::TEXT));
        self::assertSame(50.0, Coverage::validate($this->directory)['lines']);
    }

    public function testPublicationSupportsSharedReportDirectories(): void
    {
        chmod($this->directory, 0775);
        self::assertSame(50.0, Coverage::validate($this->directory)['lines']);
        self::assertFileExists($this->directory . '/metrics.json');
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidReports(): iterable
    {
        yield 'missing summary' => [str_replace('Lines:   50.00% (2/4)', '', self::TEXT), self::XML];
        yield 'duplicate summary' => [self::TEXT . "\nLines: 50%\n", self::XML];
        foreach (['nan', 'inf', '-1', '100.01', 'broken'] as $value) {
            yield 'percentage ' . $value => [str_replace('Lines:   50.00%', 'Lines:   ' . $value . '%', self::TEXT), self::XML];
        }
        foreach (['', 'broken', '<coverage/>', '<coverage><project><metrics/></project></coverage>'] as $index => $xml) {
            yield 'invalid XML ' . $index => [self::TEXT, $xml];
        }
        foreach (['-1', 'nan', '2.5', '5'] as $count) {
            yield 'invalid count ' . $count => [self::TEXT, str_replace('coveredstatements="2"', 'coveredstatements="' . $count . '"', self::XML)];
        }
        foreach (['statements' => '4', 'methods' => '2', 'classes' => '2'] as $name => $value) {
            yield 'zero ' . $name => [self::TEXT, str_replace($name . '="' . $value . '"', $name . '="0"', self::XML)];
        }
        yield 'duplicate metrics' => [self::TEXT, str_replace('</project>', '<metrics files="1"/></project>', self::XML)];
        foreach (['conditionals="1" coveredconditionals="2"', 'elements="4" coveredelements="nan"', 'loc="-1"'] as $attributes) {
            yield 'optional counts ' . $attributes => [self::TEXT, str_replace('<metrics files="1"', '<metrics ' . $attributes . ' files="1"', self::XML)];
        }
        yield 'empty executable lines' => [self::TEXT, str_replace(['statements="2"', 'statements="4"'], 'statements="0"', self::XML)];
        yield 'file disagreement' => [self::TEXT, str_replace('<metrics files="1"', '<metrics files="2"', self::XML)];
        yield 'text count disagreement' => [str_replace('Lines:   50.00% (2/4)', 'Lines:   50.00% (3/6)', self::TEXT), self::XML];
        yield 'percentage disagreement' => [str_replace('Lines:   50.00%', 'Lines:   49.98%', self::TEXT), self::XML];
    }

    #[DataProvider('invalidReports')]
    public function testInvalidReportsCannotReplacePreviouslyPublishedMetrics(string $text, string $xml): void
    {
        $this->write($text, $xml);
        file_put_contents($this->directory . '/metrics.json', '{"previous":true}');
        try {
            Coverage::validate($this->directory);
            self::fail('Expected invalid coverage reports to be rejected.');
        } catch (RuntimeException) {
            self::assertSame('{"previous":true}', file_get_contents($this->directory . '/metrics.json'));
            self::assertSame([], glob($this->directory . '/.metrics*'));
        }
    }

    public function testMissingReportsAreRejected(): void
    {
        foreach (['report.txt', 'clover.xml'] as $filename) {
            $this->write();
            unlink($this->directory . '/' . $filename);
            try {
                Coverage::validate($this->directory);
                self::fail('Expected missing report rejection.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($this->directory . '/metrics.json');
            }
        }
    }

    public function testRoundingToleranceIncludesExactlyOneHundredth(): void
    {
        $this->write(str_replace('Lines:   50.00%', 'Lines:   49.99%', self::TEXT));
        self::assertSame(49.99, Coverage::validate($this->directory)['lines']);
    }

    public function testPercentageBeyondToleranceIsRejectedWithoutFloatRounding(): void
    {
        $this->write(str_replace('Lines:   50.00%', 'Lines:   49.989999999999999999999%', self::TEXT));
        $this->expectExceptionMessage('text percentage disagrees with Clover');
        Coverage::validate($this->directory);
    }

    public function testZeroCoveredCountsRemainValid(): void
    {
        $text = str_replace(['50.00%', '(1/2)', '(2/4)'], ['0.00%', '(0/2)', '(0/4)'], self::TEXT);
        $xml = str_replace(
            ['coveredmethods="1"', 'coveredstatements="2"'],
            ['coveredmethods="0"', 'coveredstatements="0"'],
            self::XML,
        );
        $this->write($text, $xml);
        self::assertSame(['classes' => 0.0, 'methods' => 0.0, 'lines' => 0.0], Coverage::validate($this->directory));
    }

    public function testBranchCoverageDeterminesFullyCoveredClasses(): void
    {
        $this->write(
            str_replace('Classes: 50.00% (1/2)', 'Classes: 0.00% (0/2)', self::TEXT),
            str_replace('conditionals="0" coveredconditionals="0"', 'conditionals="2" coveredconditionals="1"', self::XML),
        );
        self::assertSame(0.0, Coverage::validate($this->directory)['classes']);
    }

    public function testFailedPublicationRemovesStagingFile(): void
    {
        mkdir($this->directory . '/metrics.json');
        file_put_contents($this->directory . '/metrics.json/retained', 'retained');
        try {
            Coverage::validate($this->directory);
            self::fail('Expected replacement failure.');
        } catch (RuntimeException) {
            self::assertSame('retained', file_get_contents($this->directory . '/metrics.json/retained'));
            self::assertSame([], glob($this->directory . '/.metrics*'));
        }
    }

    public function testCliDefaultDirectoryAndFailureStatus(): void
    {
        mkdir($this->directory . '/coverage', 0700);
        rename($this->directory . '/report.txt', $this->directory . '/coverage/report.txt');
        rename($this->directory . '/clover.xml', $this->directory . '/coverage/clover.xml');
        $entrypoint = dirname(__DIR__, 2) . '/validate-backend-coverage.php';
        $executor = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        try {
            self::assertSame(0, $executor->run(new ProcessSpec([PHP_BINARY, $entrypoint], cwd: $this->directory))->exitCode);
            unlink($this->directory . '/coverage/clover.xml');
            self::assertSame(1, $executor->run(new ProcessSpec([PHP_BINARY, $entrypoint, $this->directory . '/coverage']), new CompletionPolicy([1]))->exitCode);
        } finally {
            $executor->close();
        }
    }
}
