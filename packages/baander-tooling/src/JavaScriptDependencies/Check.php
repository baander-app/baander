<?php

declare(strict_types=1);

namespace Baander\Tooling\JavaScriptDependencies;

use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessSpec;

final class Check
{
    /** @param array<string,string> $environment */
    public function __construct(private readonly CommandExecutor $executor, private readonly float $timeout = 30, private readonly array $environment = []) {}

    public function run(string $root, bool $localOnly = true): Report
    {
        $report = new Report($localOnly);
        try {
            $policy = Inventory::json($root . '/packages/baander-tooling/resources/javascript-dependencies.json');
            $projects = Inventory::collect($root, $policy, $report);
        } catch (\Throwable $error) {
            $report->add('gap', 'repository', 'inventory', $error->getMessage());
            return $report;
        }
        if ($localOnly) {
            $report->add('evidence', 'repository', 'scope', 'Offline structural inspection only; resolved versions, Yarn lock semantics, freshness and advisories require --online.');
            return $report;
        }
        $latest = [];
        $internal = array_column($projects, 'manifest')
            |> (static fn(array $manifests): array => array_column($manifests, 'name'));
        foreach ($projects as $path => $project) {
            $cwd = $root . '/' . dirname($path);
            $classification = $project['classification'];
            $resolved = [];
            $direct = [];
            if ($classification['kind'] === 'locked') {
                $yarn = $classification['manager'] === 'yarn';
                try {
                    if ($yarn) {
                        $lines = $this->jsonLines($this->execute(['yarn', 'info', '--all', '--recursive', '--virtuals', '--json'], $cwd));
                        $byLocator = [];
                        $rootDependencies = [];
                        foreach ($lines as $line) {
                            $locator = $line['value'] ?? null;
                            $version = $line['children']['Version'] ?? null;
                            if (!is_string($locator) || !is_string($version) || preg_match('/^(@[^\/]+\/[^@]+|[^@]+)@/', $locator, $match) !== 1) {
                                throw new \RuntimeException('Malformed Yarn resolved package record.');
                            }
                            $resolved[$match[1]][] = $version;
                            $byLocator[$locator] = $version;
                            if ($locator === ($project['manifest']['name'] ?? '') . '@workspace:.') {
                                $rootDependencies = $line['children']['Dependencies'] ?? [];
                            }
                            if (str_contains($locator, '@patch:')) {
                                $builtin = str_contains($locator, 'builtin<compat/');
                                $report->add($builtin ? 'evidence' : 'finding', $path, $builtin ? 'builtin-patch' : 'resolved-patch', $builtin ? 'Yarn-generated compatibility patch; package manager owns it.' : 'Resolved authored patch requires policy and file review.', ['locator' => $locator, 'version' => $version]);
                            }
                        }
                        foreach ($rootDependencies as $dependency) {
                            if (preg_match('/^(@[^\/]+\/[^@]+|[^@]+)@/', $dependency['descriptor'] ?? '', $match) === 1 && isset($byLocator[$dependency['locator'] ?? ''])) {
                                $direct[$match[1]] = [$byLocator[$dependency['locator']]];
                            }
                        }
                    } else {
                        $tree = json_decode($this->execute(['npm', 'ls', '--package-lock-only', '--all', '--json'], $cwd), true, flags: JSON_THROW_ON_ERROR);
                        if (!is_array($tree) || !isset($tree['dependencies'])) {
                            throw new \RuntimeException('Malformed npm resolved dependency tree.');
                        }
                        self::npmVersions($tree, $resolved);
                        foreach ($tree['dependencies'] as $name => $dependency) {
                            $direct[$name] = [$dependency['version']];
                        }
                    }
                    $report->add('evidence', $path, 'resolved', 'Package manager reported owned resolved dependency versions.', ['packages' => $resolved, 'direct' => $direct]);
                } catch (\Throwable $error) {
                    $report->add('gap', $path, 'resolved', $error->getMessage());
                }
                try {
                    $output = $this->execute($yarn ? ['yarn', 'npm', 'audit', '--all', '--recursive', '--json'] : ['npm', 'audit', '--package-lock-only', '--json', '--ignore-scripts'], $cwd, true);
                    if ($yarn) {
                        $advisories = $this->jsonLines($output, true);
                        foreach ($advisories as $advisory) {
                            if (!isset($advisory['children']['ID'])) {
                                throw new \RuntimeException('Unrecognized Yarn advisory response.');
                            }
                        }
                    } else {
                        $audit = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                        if (!is_array($audit) || !isset($audit['vulnerabilities']) || isset($audit['error'])) {
                            throw new \RuntimeException('Unrecognized npm advisory response.');
                        }
                        $advisories = $audit['vulnerabilities'];
                    }
                    $report->add($advisories === [] ? 'evidence' : 'finding', $path, 'advisories', 'Fresh package-manager advisory response.', ['advisories' => $advisories]);
                } catch (\Throwable $error) {
                    $report->add('gap', $path, 'advisories', $error->getMessage());
                }
            }
            foreach ($project['requirements'] as $name => $declared) {
                if (in_array($name, $internal, true) || preg_match('/^(?:workspace|file|link|portal|patch|https?|git|npm):/', $declared) === 1) {
                    continue;
                }
                if (!array_key_exists($name, $latest)) {
                    try {
                        $value = json_decode($this->execute(['npm', 'view', $name . '@latest', 'version', 'engines', 'deprecated', '--json', '--prefer-online', '--fetch-retries=0'], $root), true, flags: JSON_THROW_ON_ERROR);
                        if (is_string($value)) {
                            $value = ['version' => $value];
                        }
                        if (!is_array($value) || !is_string($value['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', $value['version']) !== 1) {
                            throw new \RuntimeException('Registry did not establish a stable latest version.');
                        }
                        $latest[$name] = $value;
                    } catch (\Throwable $error) {
                        $latest[$name] = ['error' => $error->getMessage()];
                    }
                }
                if (isset($latest[$name]['error'])) {
                    $report->add('gap', $path, 'freshness', $latest[$name]['error'], ['package' => $name]);
                    continue;
                }
                $exception = null;
                foreach ($policy['exceptions'] as $candidate) {
                    if ($candidate['package'] === $name && in_array($path, $candidate['projects'], true) && in_array($declared, $candidate['declared'], true)) {
                        $exception = $candidate['reason'];
                    }
                }
                $versions = ($direct[$name] ?? []) |> array_unique(...) |> array_values(...);
                if ($classification['kind'] === 'locked' && $versions === []) {
                    $report->add('gap', $path, 'direct-version', 'Direct resolved version was not established; declared constraint is not installed-version evidence.', ['package' => $name, 'declared' => $declared]);
                }
                $current = $versions === [] && $classification['kind'] !== 'locked' ? ltrim($declared, '^~') : null;
                $matches = $versions === [] ? $current === $latest[$name]['version'] : in_array($latest[$name]['version'], $versions, true);
                $report->add($matches ? 'evidence' : 'finding', $path, 'freshness', $matches ? 'Latest stable release is present.' : 'Latest stable release requires compatibility review.', ['package' => $name, 'declared' => $declared, 'resolved' => $versions, 'latest' => $latest[$name]['version'], 'exception' => $exception, 'latestEngines' => $latest[$name]['engines'] ?? null, 'latestDeprecation' => $latest[$name]['deprecated'] ?? null]);
            }
        }
        return $report;
    }

    /** @param non-empty-list<string> $argv */
    private function execute(array $argv, string $cwd, bool $audit = false): string
    {
        $result = $this->executor->run(new ProcessSpec($argv, $cwd, [...$this->environment, 'YARN_ENABLE_SCRIPTS' => 'false', 'npm_config_ignore_scripts' => 'true'], $this->timeout), new CompletionPolicy($audit ? [0, 1] : [0]));
        (new CompletionPolicy($audit ? [0, 1] : [0]))->complete($result);
        if ($audit && $result->exitCode !== 0 && trim($result->stdout) === '') {
            throw new \RuntimeException('Audit failed without advisory evidence.');
        }
        return $result->stdout;
    }

    /** @return list<array<string,mixed>> */
    private function jsonLines(string $output, bool $allowEmpty = false): array
    {
        if (trim($output) === '') {
            if ($allowEmpty) {
                return [];
            }
            throw new \RuntimeException('Empty package-manager response.');
        }
        $records = [];
        foreach (explode("\n", trim($output)) as $line) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($record)) {
                throw new \RuntimeException('Malformed package-manager JSON record.');
            }
            $records[] = $record;
        }
        return $records;
    }

    /** @param array<string,mixed> $tree
     * @param array<string,list<string>> $versions
     */
    private static function npmVersions(array $tree, array &$versions): void
    {
        foreach ($tree['dependencies'] ?? [] as $name => $dependency) {
            if (!is_string($name) || !is_array($dependency) || !is_string($dependency['version'] ?? null)) {
                throw new \RuntimeException('Malformed npm resolved version.');
            }
            $versions[$name][] = $dependency['version'];
            self::npmVersions($dependency, $versions);
        }
    }
}
