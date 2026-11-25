<?php

declare(strict_types=1);

namespace Baander\DevDependencies\JavaScript;

use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\ProcessSpec;

final class Check
{
    /** @param array<string,string> $environment */
    public function __construct(private readonly CommandExecutor $executor, private readonly float $timeout = 30, private readonly array $environment = []) {}

    public function run(string $root, bool $localOnly = true): Report
    {
        $report = new Report($localOnly);
        try {
            $policy = Inventory::json($root . '/packages/baander-dev-dependencies/resources/javascript-dependencies.json');
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
                    $advisories = $this->audit($cwd, $yarn);
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

    /**
     * Audits every owned lock against the registry's advisories and assesses nothing else. A lock
     * whose audit did not complete is a gap, so an unreachable registry cannot read as a clean lock.
     */
    public function advisories(string $root): Report
    {
        $report = new Report(localOnly: false, advisoriesOnly: true);
        try {
            $policy = Inventory::json($root . '/packages/baander-dev-dependencies/resources/javascript-dependencies.json');
            $projects = Inventory::collect($root, $policy, $report);
        } catch (\Throwable $error) {
            $report->add('gap', 'repository', 'inventory', $error->getMessage());
            return $report;
        }
        foreach ($projects as $path => $project) {
            $classification = $project['classification'];
            if ($classification['kind'] !== 'locked') {
                continue;
            }
            $yarn = $classification['manager'] === 'yarn';
            try {
                ['advisories' => $advisories, 'deprecations' => $deprecations] = self::classify($this->audit($root . '/' . dirname($path), $yarn), $yarn);
            } catch (\Throwable $error) {
                $report->add('gap', $path, 'advisories', $error->getMessage());
                continue;
            }
            $unlisted = [];
            foreach ($advisories as $advisory) {
                $exception = array_find($policy['advisoryExceptions'], static fn(array $exception): bool => $exception['package'] === $advisory['package'] && $exception['advisory'] === $advisory['advisory'] && in_array($path, $exception['projects'], true));
                if ($exception === null) {
                    $unlisted[] = $advisory;
                } else {
                    $report->add('evidence', $path, 'advisory-exception', $exception['reason'], $advisory);
                }
            }
            if ($unlisted === []) {
                $report->add('evidence', $path, 'advisories', 'No security advisory without a policy exception applies to the owned lock.');
            } else {
                $report->add('finding', $path, 'advisories', 'Security advisories without a policy exception apply to the owned lock.', ['advisories' => $unlisted]);
            }
            if ($deprecations !== []) {
                $report->add('evidence', $path, 'deprecations', 'Deprecated packages are reported without failing the advisory check.', ['deprecations' => $deprecations]);
            }
        }
        return $report;
    }

    /** @return array<array-key,mixed> Yarn's advisory records, or npm's vulnerabilities keyed by package */
    private function audit(string $cwd, bool $yarn): array
    {
        $output = $this->execute($yarn ? ['yarn', 'npm', 'audit', '--all', '--recursive', '--json'] : ['npm', 'audit', '--package-lock-only', '--json', '--ignore-scripts'], $cwd, true);
        if ($yarn) {
            $advisories = $this->jsonLines($output, true);
            foreach ($advisories as $advisory) {
                if (!isset($advisory['children']['ID'])) {
                    throw new \RuntimeException('Unrecognized Yarn advisory response.');
                }
            }
            return $advisories;
        }
        $audit = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($audit) || !is_array($audit['vulnerabilities'] ?? null) || isset($audit['error'])) {
            throw new \RuntimeException('Unrecognized npm advisory response.');
        }
        return $audit['vulnerabilities'];
    }

    /**
     * Reduces an audit response to one entry per package and advisory. Yarn mixes deprecation
     * notices into its advisory records and marks them only through the ID suffix; npm lists a
     * package affected through a dependency with that dependency's name in place of an advisory.
     *
     * @param array<array-key,mixed> $response
     * @return array{advisories: list<array<string,mixed>>, deprecations: list<array<string,mixed>>}
     */
    private static function classify(array $response, bool $yarn): array
    {
        $advisories = [];
        $deprecations = [];
        foreach ($response as $entry) {
            if ($yarn) {
                $package = $entry['value'] ?? null;
                $record = $entry['children'];
                if (!is_string($package) || $package === '') {
                    throw new \RuntimeException('Unrecognized Yarn advisory response.');
                }
                if (is_string($record['ID']) && str_ends_with($record['ID'], ' (deprecation)')) {
                    $deprecations[] = ['package' => $package, 'record' => $record];
                    continue;
                }
                $advisory = self::identifier($record['URL'] ?? null, $record['ID']);
                $advisories[$package . ' ' . $advisory] = ['package' => $package, 'advisory' => $advisory, 'severity' => $record['Severity'] ?? null, 'title' => $record['Issue'] ?? null, 'record' => $record];
                continue;
            }
            $via = is_array($entry) ? $entry['via'] ?? null : null;
            if (!is_array($via) || $via === []) {
                throw new \RuntimeException('Unrecognized npm advisory response.');
            }
            foreach ($via as $source) {
                if (is_string($source) && isset($response[$source])) {
                    continue;
                }
                if (!is_array($source) || !is_string($source['name'] ?? null) || $source['name'] === '') {
                    throw new \RuntimeException('Unrecognized npm advisory response.');
                }
                $advisory = self::identifier($source['url'] ?? null, $source['source'] ?? null);
                $advisories[$source['name'] . ' ' . $advisory] = ['package' => $source['name'], 'advisory' => $advisory, 'severity' => $source['severity'] ?? null, 'title' => $source['title'] ?? null, 'record' => $source];
            }
        }
        if ($response !== [] && $advisories === [] && $deprecations === []) {
            throw new \RuntimeException('Audit reported vulnerable packages without an advisory.');
        }
        return ['advisories' => array_values($advisories), 'deprecations' => $deprecations];
    }

    /** The GitHub advisory identifier in the registry's link names an advisory the same way under Yarn and npm; the registry's numeric identifier is the fallback. */
    private static function identifier(mixed $url, mixed $id): string
    {
        if (is_string($url) && preg_match('~/(GHSA(?:-[0-9a-z]{4}){3})$~', $url, $match) === 1) {
            return $match[1];
        }
        if (!is_int($id)) {
            throw new \RuntimeException('Advisory record has no identifier.');
        }
        return (string) $id;
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
