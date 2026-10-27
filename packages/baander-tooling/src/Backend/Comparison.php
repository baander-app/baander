<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use Baander\Tooling\Development\Runner;
use RuntimeException;

final readonly class Comparison
{
    private const string EXTRACT_COVERAGE = <<<'PHP_CODE'
        require $argv[1];
        $data = (new SebastianBergmann\CodeCoverage\Serialization\Merger())->merge(array_slice($argv, 2));
        $files = $lines = $covered = [];
        foreach ($data['codeCoverage']->lineCoverage() as $file => $entries) {
            $absolute = str_starts_with($file, '/') ? $file : rtrim($data['basePath'], '/') . '/' . $file;
            $files[] = $absolute;
            foreach ($entries as $number => $tests) {
                if ($tests === null) {
                    continue;
                }
                $lines[] = [$absolute, $number];
                if ($tests !== []) {
                    $covered[] = [$absolute, $number];
                }
            }
        }
        echo json_encode(['files' => $files, 'lines' => $lines, 'covered' => $covered], JSON_THROW_ON_ERROR);
        PHP_CODE;

    public function __construct(
        private string $root,
        private Runner $runner = new Runner(),
    ) {}

    /** @return array<string,mixed> */
    private static function json(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read backend result: ' . $path);
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid backend result: ' . $path);
        }
        return $data;
    }

    /** @return array{list<string>,mixed,bool} */
    public static function identities(string $directory): array
    {
        $discovery = self::json($directory . '/run/discovery.json');
        if (
            !is_array($discovery['files'] ?? null)
            || !is_string($discovery['runId'] ?? null)
            || !is_bool($discovery['coverage'] ?? null)
        ) {
            throw new RuntimeException('Invalid backend discovery: ' . $directory);
        }
        $expected = [];
        foreach ($discovery['files'] as $values) {
            if (!is_array($values)) {
                throw new RuntimeException('Invalid discovered test identities');
            }
            foreach ($values as $identity) {
                if (!is_string($identity)) {
                    throw new RuntimeException('Invalid discovered test identity');
                }
                $expected[] = $identity;
            }
        }
        sort($expected, SORT_STRING);
        $observed = [];
        foreach (glob($directory . '/run/*.result.json') ?: [] as $path) {
            $result = self::json($path);
            if (
                ($result['status'] ?? null) !== 0
                || ($result['runId'] ?? null) !== $discovery['runId']
                || !is_array($result['identities'] ?? null)
            ) {
                throw new RuntimeException('Failed or stale result: ' . $path);
            }
            foreach ($result['identities'] as $identity) {
                if (!is_string($identity)) {
                    throw new RuntimeException('Invalid completed test identity');
                }
                $observed[] = $identity;
            }
        }
        sort($observed, SORT_STRING);
        if ($expected === [] || count($expected) !== count(array_unique($expected)) || $expected !== $observed) {
            throw new RuntimeException('Incomplete or duplicate results: ' . $directory);
        }
        return [$expected, $discovery['seed'] ?? null, $discovery['coverage']];
    }

    /** @return array{array<string,int>,list<string>,list<string>} */
    public function coverage(string $directory): array
    {
        $discovery = self::json($directory . '/run/discovery.json');
        $paths = [];
        foreach (glob($directory . '/run/*.result.json') ?: [] as $resultPath) {
            $result = self::json($resultPath);
            $path = substr($resultPath, 0, -strlen('.result.json')) . '.cov';
            if (($result['status'] ?? null) !== 0 || ($result['runId'] ?? null) !== ($discovery['runId'] ?? null)) {
                throw new RuntimeException('Failed or stale coverage result: ' . $resultPath);
            }
            if (!is_file($path) || hash_file('sha256', $path) !== ($result['hashes']['coverage'] ?? null)) {
                throw new RuntimeException('Missing or changed serialized coverage: ' . $path);
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new RuntimeException('Cannot resolve serialized coverage: ' . $path);
            }
            $paths[] = $resolved;
        }
        if ($paths === []) {
            throw new RuntimeException('No serialized coverage lanes found');
        }
        $extracted = $this->runner->run(
            [PHP_BINARY, '-d', 'memory_limit=2G', '-r', self::EXTRACT_COVERAGE, $this->root . '/vendor/autoload.php', ...$paths],
            timeout: 120,
            check: false,
        );
        if ($extracted->exitCode !== 0) {
            throw new RuntimeException('Cannot read serialized coverage: ' . trim($extracted->stderr));
        }
        $data = json_decode($extracted->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (
            !is_array($data)
            || !is_array($data['files'] ?? null)
            || !is_array($data['lines'] ?? null)
            || !is_array($data['covered'] ?? null)
        ) {
            throw new RuntimeException('Invalid serialized coverage extraction');
        }
        $lines = self::lineSet($data['lines']);
        $covered = self::lineSet($data['covered']);
        $metrics = Coverage::nodes(Coverage::document($directory . '/reports/clover.xml'), '/coverage/project/metrics');
        if (count($metrics) !== 1) {
            throw new RuntimeException('Clover must contain one project metrics element');
        }
        $denominators = [];
        foreach (['files', 'classes', 'methods', 'statements'] as $name) {
            $denominators[$name] = Coverage::count($metrics[0], $name);
        }
        if ($denominators['statements'] <= 0 || count($lines) !== $denominators['statements']) {
            throw new RuntimeException('Clover line denominator disagrees with serialized executable lines');
        }
        if (count($data['files']) !== $denominators['files'] || count($covered) !== Coverage::count($metrics[0], 'coveredstatements')) {
            throw new RuntimeException('Clover file or covered-line count disagrees with serialized coverage');
        }
        return [$denominators, $lines, $covered];
    }

    /**
     * @param array<mixed> $identities
     * @return list<string>
     */
    private static function lineSet(array $identities): array
    {
        $set = [];
        foreach ($identities as $identity) {
            if (!is_array($identity) || count($identity) !== 2 || !is_string($identity[0] ?? null) || !is_int($identity[1] ?? null)) {
                throw new RuntimeException('Invalid executable line identity');
            }
            $set[json_encode($identity, JSON_THROW_ON_ERROR)] = true;
        }
        $values = array_keys($set);
        sort($values, SORT_STRING);
        return $values;
    }

    /** @return array<string,mixed> */
    public function compare(string $reference, string $candidate): array
    {
        [$expected, $seed, $hasCoverage] = self::identities($reference);
        [$actual, $candidateSeed, $candidateCoverage] = self::identities($candidate);
        if ($expected !== $actual || $seed !== $candidateSeed || $hasCoverage !== $candidateCoverage) {
            throw new RuntimeException('Selected/completed identities, seed or coverage mode differ');
        }
        $result = ['tests' => count($expected), 'seed' => $seed, 'coverage' => $hasCoverage];
        if ($hasCoverage) {
            [$baseline, $executable, $covered] = $this->coverage($reference);
            [$denominators, $candidateExecutable, $candidateCovered] = $this->coverage($candidate);
            if ($baseline !== $denominators || $executable !== $candidateExecutable || $covered !== $candidateCovered) {
                $differences = [
                    'denominators' => [$baseline, $denominators],
                    'executable_difference' => count(array_diff($executable, $candidateExecutable)) + count(array_diff($candidateExecutable, $executable)),
                    'covered_difference' => count(array_diff($covered, $candidateCovered)) + count(array_diff($candidateCovered, $covered)),
                ];
                throw new RuntimeException(json_encode($differences, JSON_THROW_ON_ERROR));
            }
            $result['denominators'] = $baseline;
            $result['covered_lines'] = count($covered);
        }
        foreach (['reference' => $reference, 'candidate' => $candidate] as $label => $directory) {
            $timing = self::json($directory . '/timing.json');
            if (($timing['status'] ?? null) !== 0 || !is_numeric($timing['elapsed'] ?? null)) {
                throw new RuntimeException($label . ' run did not complete successfully');
            }
            $result[$label . '_seconds'] = (float) $timing['elapsed'];
        }
        if ($result['candidate_seconds'] <= 0) {
            throw new RuntimeException('Candidate elapsed time must be positive');
        }
        $result['speedup'] = $result['reference_seconds'] / $result['candidate_seconds'];
        return $result;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if (count($arguments) !== 2) {
            throw new \InvalidArgumentException('Usage: compare-backend-runs.php REFERENCE CANDIDATE');
        }
        $result = new self($root)->compare(...$arguments);
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
}
