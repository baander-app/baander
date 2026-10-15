<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

final class BackendTestRunner
{
    public static function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing manifest: ' . $path);
        }
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Invalid manifest: ' . $path);
        }
        return $value;
    }

    public static function writeJson(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    }

    private static function xml(string $path): DOMDocument
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing XML report: ' . $path);
        }
        $document = new DOMDocument();
        if (!$document->load($path, LIBXML_NONET)) {
            throw new RuntimeException('Invalid XML report: ' . $path);
        }
        return $document;
    }

    private static function process(array $command): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if ($process === false) {
            throw new RuntimeException('Could not start test process.');
        }
        return proc_close($process);
    }

    public static function observed(string $path): array
    {
        $identities = [];
        foreach (self::xml($path)->getElementsByTagName('testcase') as $test) {
            $name = $test->getAttribute('name');
            if (preg_match('/^(.*?) with data set (?:#(\d+)|"(.*)")$/s', $name, $match)) {
                $name = $match[1] . '#' . (($match[2] ?? '') !== '' ? $match[2] : $match[3]);
            }
            $identities[] = str_replace('.', '\\', $test->getAttribute('classname')) . '::' . $name;
        }
        return $identities;
    }

    public static function equalIdentities(array $expected, array $observed): void
    {
        if ($expected === [] || count(array_unique($expected)) !== count($expected) || count(array_unique($observed)) !== count($observed)) {
            throw new RuntimeException('Empty or duplicate test identities.');
        }
        sort($expected);
        sort($observed);
        if ($expected !== $observed) {
            throw new RuntimeException('Missing or unexpected test identities: ' . json_encode([
                'missing' => array_values(array_diff($expected, $observed)),
                'unexpected' => array_values(array_diff($observed, $expected)),
            ], JSON_THROW_ON_ERROR));
        }
    }

    public static function discover(string $directory, array $input): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create run directory.');
        }
        $configuration = 'phpunit.xml.dist';
        $arguments = [];
        $reports = [];
        $noCoverage = false;
        $valueOptions = ['--filter', '--group', '--exclude-group', '--random-order-seed', '--order-by', '--coverage-filter'];
        $selectionOptions = ['--testsuite', '--exclude-testsuite'];
        $flags = ['--no-progress', '--display-all-issues', '--no-coverage', '--do-not-cache-result'];
        $discoveryArguments = [];
        for ($i = 0; $i < count($input); $i++) {
            $argument = $input[$i];
            [$option, $value] = array_pad(explode('=', $argument, 2), 2, null);
            if (in_array($option, ['-c', '--configuration', ...$valueOptions, ...$selectionOptions, '--colors', '--coverage-text', '--coverage-clover'], true)) {
                $value ??= $input[++$i] ?? null;
                if ($value === null || $value === '') {
                    throw new RuntimeException('Missing option value: ' . $option);
                }
                if (in_array($option, ['-c', '--configuration'], true)) {
                    $configuration = $value;
                } elseif (in_array($option, ['--coverage-text', '--coverage-clover'], true)) {
                    $reports[$option] = $value;
                } else {
                    $discoveryArguments[] = $option . '=' . $value;
                    if (!in_array($option, $selectionOptions, true)) {
                        $arguments[] = $option . '=' . $value;
                    }
                }
            } elseif (in_array($argument, $flags, true) || preg_match('/^--(?:do-not-)?fail-on-(?:warning|risky|deprecation|phpunit-deprecation|phpunit-notice|phpunit-warning|notice|skipped|incomplete|all-issues|empty-test-suite)$/', $argument)) {
                if ($argument === '--no-coverage') {
                    $noCoverage = true;
                } else {
                    $arguments[] = $argument;
                    $discoveryArguments[] = $argument;
                }
            } elseif (!str_starts_with($argument, '-') && file_exists($argument)) {
                $discoveryArguments[] = $argument;
            } else {
                throw new RuntimeException('Unsupported runner argument: ' . $argument);
            }
        }
        if ($noCoverage && $reports !== []) {
            throw new RuntimeException('Cannot combine --no-coverage with coverage report options.');
        }
        $configuration = realpath($configuration) ?: throw new RuntimeException('Configuration does not exist.');
        $original = self::xml($configuration);
        if ((new DOMXPath($original))->query('/phpunit/testsuites/testsuite[@bootstrap]')->length > 0) {
            throw new RuntimeException('Suite bootstrap configuration is unsupported by the lane runner.');
        }
        $order = $original->documentElement->getAttribute('executionOrder') ?: 'default';
        $seed = random_int(1, 2147483647);
        $explicitOrder = false;
        $explicitSeed = false;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--order-by=')) {
                $order = substr($argument, strlen('--order-by='));
                $explicitOrder = true;
            }
            if (str_starts_with($argument, '--random-order-seed=')) {
                $seed = (int) substr($argument, strlen('--random-order-seed='));
                $explicitSeed = true;
            }
        }
        if (in_array('random', explode(',', $order), true)) {
            // PHPUnit checks CLI seed/order consistency before merging the XML configuration.
            if (!$explicitOrder) {
                $arguments[] = $discoveryArguments[] = '--order-by=' . $order;
            }
            if (!$explicitSeed) {
                $arguments[] = $discoveryArguments[] = '--random-order-seed=' . $seed;
            }
        } elseif ($explicitSeed) {
            throw new RuntimeException('A random seed requires random execution order.');
        }
        $status = self::process([PHP_BINARY, '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '-c', $configuration, ...$discoveryArguments, '--no-coverage', '--list-tests-xml', $directory . '/discovery.xml']);
        if ($status !== 0) {
            throw new RuntimeException('Test discovery failed with status ' . $status);
        }
        $files = [];
        foreach (self::xml($directory . '/discovery.xml')->getElementsByTagName('testClass') as $class) {
            foreach ($class->getElementsByTagName('testMethod') as $test) {
                $files[$class->getAttribute('file')][] = $test->getAttribute('id');
            }
        }
        self::equalIdentities(array_merge(...array_values($files ?: [[]])), array_merge(...array_values($files ?: [[]])));
        self::writeJson($directory . '/discovery.json', ['files' => $files, 'arguments' => $arguments, 'config' => $configuration, 'seed' => $seed, 'coverage' => $reports !== [], 'reports' => $reports, 'runId' => bin2hex(random_bytes(16))]);
    }

    public static function laneConfiguration(array $discovery, array $files, string $target): void
    {
        $document = self::xml($discovery['config']);
        $root = $document->documentElement;
        $base = dirname($discovery['config']);
        foreach (['bootstrap', 'cacheDirectory', 'extensionsDirectory'] as $attribute) {
            if ($root->hasAttribute($attribute) && !str_starts_with(trim($root->getAttribute($attribute)), '/') && !str_contains($root->getAttribute($attribute), '://')) {
                $root->setAttribute($attribute, $base . '/' . trim($root->getAttribute($attribute)));
            }
        }
        foreach ((new DOMXPath($document))->query('//directory | //file | /phpunit/php/includePath') as $element) {
            if (!str_starts_with(trim($element->textContent), '/') && !str_contains($element->textContent, '://')) {
                $element->nodeValue = $base . '/' . trim($element->textContent);
            }
        }
        foreach ((new DOMXPath($document))->query('/phpunit/source[@baseline]') as $source) {
            $path = trim($source->getAttribute('baseline'));
            if (!str_starts_with($path, '/') && !str_contains($path, '://')) {
                $source->setAttribute('baseline', $base . '/' . $path);
            }
        }
        foreach (iterator_to_array($root->childNodes) as $child) {
            if (in_array($child->nodeName, ['testsuites', 'logging', 'coverage'], true)) {
                // Keep coverage configuration and exclusions, but remove report destinations.
                if ($child->nodeName === 'coverage') {
                    foreach (iterator_to_array($child->childNodes) as $coverageChild) {
                        if ($coverageChild->nodeName === 'report') {
                            $child->removeChild($coverageChild);
                        }
                    }
                } else {
                    $root->removeChild($child);
                }
            }
        }
        $suites = $root->appendChild($document->createElement('testsuites'));
        $suite = $suites->appendChild($document->createElement('testsuite'));
        $suite->setAttribute('name', 'BackendLane');
        foreach ($files as $file) {
            $entry = $suite->appendChild($document->createElement('file'));
            $entry->appendChild($document->createTextNode($file));
        }
        $document->save($target);
    }

    public static function run(string $directory, string $lane): void
    {
        self::validLane($lane);
        $discovery = self::readJson($directory . '/discovery.json');
        $manifest = self::readJson($directory . '/' . $lane . '.json');
        $expected = self::selected($discovery, $manifest);
        $config = dirname(__DIR__) . '/.backend-lane-' . $discovery['runId'] . '-' . $lane . '.xml';
        self::laneConfiguration($discovery, $manifest['files'], $config);
        $prefix = $directory . '/' . $lane;
        foreach (['.junit.xml', '.cov', '.result.json'] as $suffix) {
            if (is_file($prefix . $suffix)) {
                unlink($prefix . $suffix);
            }
        }
        $command = [PHP_BINARY, '-d', 'memory_limit=2G', 'vendor/bin/paratest', '-c', $config, ...$discovery['arguments'], '--processes=' . $manifest['processes'], '--max-batch-size=' . $manifest['batch'], '--passthru-php=-d memory_limit=512M', '--log-junit=' . $prefix . '.junit.xml'];
        if ($discovery['coverage']) {
            $command[] = '--coverage-php=' . $prefix . '.cov';
        }
        try {
            $status = self::process($command);
            $observed = self::observed($prefix . '.junit.xml');
            self::equalIdentities($expected, $observed);
            $hashes = ['junit' => hash_file('sha256', $prefix . '.junit.xml')];
            if ($discovery['coverage']) {
                (new Unserializer())->unserialize($prefix . '.cov');
                $hashes['coverage'] = hash_file('sha256', $prefix . '.cov');
            }
            self::writeJson($prefix . '.result.json', ['status' => $status, 'identities' => $observed, 'count' => count($observed), 'runId' => $discovery['runId'], 'hashes' => $hashes]);
            if ($status !== 0) {
                throw new RuntimeException('Lane failed with status ' . $status);
            }
        } finally {
            unlink($config);
        }
    }

    private static function validLane(string $lane): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $lane) || in_array($lane, ['discovery', 'finalize'], true)) {
            throw new RuntimeException('Invalid lane name.');
        }
    }

    private static function selected(array $discovery, array $manifest): array
    {
        if (!isset($manifest['files'], $manifest['processes'], $manifest['batch']) || !is_array($manifest['files']) || !is_int($manifest['processes']) || $manifest['processes'] < 1 || $manifest['processes'] > 8 || !is_int($manifest['batch']) || $manifest['batch'] < 1) {
            throw new RuntimeException('Invalid lane manifest.');
        }
        $expected = [];
        foreach ($manifest['files'] as $file) {
            if (!isset($discovery['files'][$file])) {
                throw new RuntimeException('Unknown lane file: ' . $file);
            }
            array_push($expected, ...$discovery['files'][$file]);
        }
        self::equalIdentities($expected, $expected);
        return $expected;
    }

    public static function finalize(string $directory): void
    {
        $discovery = self::readJson($directory . '/discovery.json');
        $expected = array_merge(...array_values($discovery['files']));
        $assigned = [];
        $observed = [];
        $coverage = [];
        $signature = null;
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $lane = basename($path, '.json');
            if (in_array($lane, ['discovery', 'finalize'], true) || str_ends_with($lane, '.result')) {
                continue;
            }
            self::validLane($lane);
            $selected = self::selected($discovery, self::readJson($path));
            array_push($assigned, ...$selected);
            $result = self::readJson($directory . '/' . $lane . '.result.json');
            if ($result['status'] !== 0 || $result['runId'] !== $discovery['runId']) {
                throw new RuntimeException('Failed or stale lane: ' . $lane);
            }
            $prefix = $directory . '/' . $lane;
            if (!is_file($prefix . '.junit.xml') || !isset($result['hashes']['junit']) || hash_file('sha256', $prefix . '.junit.xml') !== $result['hashes']['junit']) {
                throw new RuntimeException('Changed JUnit artifact: ' . $lane);
            }
            $identities = self::observed($prefix . '.junit.xml');
            self::equalIdentities($selected, $identities);
            array_push($observed, ...$identities);
            if ($discovery['coverage']) {
                if (!is_file($prefix . '.cov') || !isset($result['hashes']['coverage']) || hash_file('sha256', $prefix . '.cov') !== $result['hashes']['coverage']) {
                    throw new RuntimeException('Missing or changed coverage artifact: ' . $lane);
                }
                $data = (new Unserializer())->unserialize($prefix . '.cov');
                $files = $data['codeCoverage']->coveredFiles();
                sort($files);
                // Xdebug and static analysis can classify the same source line
                // differently; the coverage merger resolves those classifications.
                $current = [$data['basePath'], $files];
                if ($signature !== null && $signature !== $current) {
                    throw new RuntimeException('Coverage source paths or filters differ.');
                }
                $signature = $current;
                $coverage[] = $prefix . '.cov';
            }
        }
        self::equalIdentities($expected, $assigned);
        self::equalIdentities($expected, $observed);
        if ($discovery['coverage']) {
            $merged = (new Merger())->merge($coverage);
            foreach ($merged['codeCoverage']->coveredFiles() as $file) {
                $merged['codeCoverage']->renameFile($file, $merged['basePath'] . '/' . $file);
            }
            $merged['basePath'] = '';
            $report = Facade::fromSerializedData($merged);
            foreach ($discovery['reports'] as $option => $target) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                $temporary = $target . '.tmp-' . $discovery['runId'];
                if ($option === '--coverage-text') {
                    $report->renderText($temporary);
                } else {
                    $report->renderClover($temporary);
                }
                if (!@rename($temporary, $target)) {
                    throw new RuntimeException('Could not publish coverage report: ' . $target);
                }
            }
        }
        self::writeJson($directory . '/finalize.json', ['status' => 0, 'count' => count($observed), 'files' => count($discovery['files']), 'runId' => $discovery['runId']]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    chdir(dirname(__DIR__));
    try {
        $mode = $_SERVER['argv'][1] ?? '';
        $directory = $_SERVER['argv'][2] ?? throw new RuntimeException('Run directory is required.');
        match ($mode) {
            'discover' => BackendTestRunner::discover($directory, array_slice($_SERVER['argv'], 3)),
            'run' => BackendTestRunner::run($directory, $_SERVER['argv'][3] ?? ''),
            'finalize' => BackendTestRunner::finalize($directory),
            default => throw new RuntimeException('Expected discover, run or finalize.'),
        };
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
