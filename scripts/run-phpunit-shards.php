<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

require dirname(__DIR__) . '/vendor/autoload.php';

chdir(dirname(__DIR__));

/** @param list<string> $arguments */
function runPhpunitProcess(array $arguments, ?string $output = null): int
{
    $descriptors = [
        0 => STDIN,
        1 => $output === null ? ['pipe', 'w'] : ['file', $output, 'w'],
        2 => $output === null ? ['redirect', 1] : STDERR,
    ];
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', 'vendor/bin/phpunit', ...$arguments],
        $descriptors,
        $pipes,
    );

    if ($process === false) {
        throw new RuntimeException('Could not start PHPUnit.');
    }

    if ($output === null) {
        stream_copy_to_stream($pipes[1], STDOUT);
        fclose($pipes[1]);
    }

    return proc_close($process);
}

/**
 * @param list<non-empty-string> $paths
 * @param array<string, non-empty-string> $reports
 */
function writeMergedCoverageReports(array $paths, array $reports): void
{
    foreach ($paths as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('Missing shard coverage: ' . $path);
        }
    }

    // Merge executed-line and test data before calculating percentages. Averaging
    // shard percentages would discard shared coverage and inflate denominators.
    $merged = (new Merger())->merge($paths);

    // Reports need source paths on disk. Restore absolute paths before the
    // report builder reduces them, including the single-file coverage case.
    foreach ($merged['codeCoverage']->coveredFiles() as $file) {
        $merged['codeCoverage']->renameFile($file, $merged['basePath'] . DIRECTORY_SEPARATOR . $file);
    }

    $merged['basePath'] = '';
    $report = Facade::fromSerializedData($merged);

    if (isset($reports['--coverage-clover'])) {
        $report->renderClover($reports['--coverage-clover']);
    }

    if (isset($reports['--coverage-text'])) {
        $report->renderText($reports['--coverage-text']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$arguments = [];
$coverageReports = [];
$inputArguments = array_slice($_SERVER['argv'] ?? [], 1);

for ($index = 0; $index < count($inputArguments); $index++) {
    $argument = $inputArguments[$index];
    // Output reports belong to the combined run, rather than the last shard.
    if (str_starts_with($argument, '--coverage-')) {
        [$option, $target] = array_pad(explode('=', $argument, 2), 2, null);

        if ($option === '--coverage-filter') {
            $arguments[] = $argument;
            continue;
        }

        if (
            $target === null
            && isset($inputArguments[$index + 1])
            && !str_starts_with($inputArguments[$index + 1], '-')
        ) {
            $target = $inputArguments[++$index];
        }

        if (!in_array($option, ['--coverage-clover', '--coverage-text'], true) || $target === null || $target === '') {
            fwrite(STDERR, 'Shard coverage supports --coverage-clover=PATH and --coverage-text=PATH.' . PHP_EOL);
            exit(2);
        }

        $coverageReports[$option] = $target;
        continue;
    }

    $arguments[] = $argument;
}

$temporaryDirectory = sys_get_temp_dir() . '/baander-phpunit-shards-' . bin2hex(random_bytes(8));

if (!mkdir($temporaryDirectory, 0700)) {
    throw new RuntimeException('Could not create PHPUnit shard directory.');
}

register_shutdown_function(static function () use ($temporaryDirectory): void {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($temporaryDirectory);
});

$discoveryXml = $temporaryDirectory . '/tests.xml';
$discoveryOutput = $temporaryDirectory . '/discovery.txt';
$status = runPhpunitProcess([
    ...$arguments,
    '--no-coverage',
    '--list-tests-xml',
    $discoveryXml,
], $discoveryOutput);

if ($status !== 0) {
    readfile($discoveryOutput);
    exit($status);
}

$document = new DOMDocument();

if (!$document->load($discoveryXml, LIBXML_NONET)) {
    throw new RuntimeException('Could not read PHPUnit test discovery.');
}

$files = [];
$testCount = 0;

foreach ($document->getElementsByTagName('testClass') as $class) {
    $file = $class->getAttribute('file');

    foreach ($class->getElementsByTagName('testMethod') as $method) {
        $files[$file][] = $method->getAttribute('id');
        $testCount++;
    }
}

if ($testCount === 0) {
    fwrite(STDERR, 'PHPUnit discovery selected no tests.' . PHP_EOL);
    exit(1);
}

// Kernel suites accumulate container state. Give each file a fresh process;
// lightweight suites use bounded batches to avoid excessive startup overhead.
$shards = [];
$batch = [];

foreach ($files as $file => $ids) {
    $usesKernel = str_contains($file, '/tests/Integration/') || str_contains($file, '/tests/Functional/');

    if ($usesKernel) {
        if ($batch !== []) {
            $shards[] = $batch;
            $batch = [];
        }

        $shards[] = [$file => $ids];
        continue;
    }

    $batch[$file] = $ids;

    if (count($batch) === 25) {
        $shards[] = $batch;
        $batch = [];
    }
}

if ($batch !== []) {
    $shards[] = $batch;
}

fwrite(STDOUT, sprintf(
    "Discovered %d tests in %d files; running %d fresh PHPUnit processes.\n",
    $testCount,
    count($files),
    count($shards),
));
$failedShards = 0;
$coveragePaths = [];

foreach ($shards as $index => $shard) {
    fwrite(STDOUT, sprintf(
        "\nPHPUnit shard %d/%d: %s\n",
        $index + 1,
        count($shards),
        implode(', ', array_keys($shard)),
    ));
    $idsFile = $temporaryDirectory . '/shard-' . $index . '.txt';
    $ids = array_merge(...array_values($shard));
    file_put_contents($idsFile, implode(PHP_EOL, $ids) . PHP_EOL);
    $shardArguments = [...$arguments, '--test-id-filter-file', $idsFile, '--fail-on-empty-test-suite'];

    if ($coverageReports !== []) {
        $coveragePath = $temporaryDirectory . '/coverage-' . $index . '.cov';
        $coveragePaths[] = $coveragePath;
        $shardArguments[] = '--coverage-php=' . $coveragePath;
    }

    if (runPhpunitProcess($shardArguments) !== 0) {
        $failedShards++;
    }
}

if ($coverageReports !== []) {
    writeMergedCoverageReports($coveragePaths, $coverageReports);
}

fwrite(STDOUT, sprintf(
    "\nCompleted %d shards for %d discovered tests; %d failed shards.\n",
    count($shards),
    $testCount,
    $failedShards,
));
exit($failedShards === 0 ? 0 : 1);
