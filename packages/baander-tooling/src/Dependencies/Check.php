<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CommandFailure;
use Composer\Semver\Semver;

final class Check
{
    private bool $stopped = false;
    private readonly CommandExecutor $executor;

    /** @param array<string,string> $environment
     */
    public function __construct(CommandExecutor $executor, private readonly ToolingConfiguration $configuration, private readonly array $environment = [])
    {
        $this->executor = new BudgetedExecutor($executor, $configuration->budgets->seconds('process'));
    }

    public function run(string $root, bool $localOnly = false): Report
    {
        $this->stopped = false;
        $report = new Report($localOnly);
        try {
            $inventory = Inventory::load($root);
        } catch (\Throwable $error) {
            $report->add('gap', 'repository', 'inventory', 'policy', $error->getMessage());
            return $report;
        }
        $runtimes = LocalEvidence::collect($root, $inventory, $report);
        $home = sys_get_temp_dir() . '/baander-dependencies-' . bin2hex(random_bytes(12));
        try {
            PrivateDirectory::prepare($home);
            $composer = new ComposerEvidence($this->executor, $this->configuration->budgets->seconds('process'), $this->environment, $home);
            $supported = $this->source($report, 'repository', 'composer-interface', 'composer', static fn(): string => $composer->version());
            if ($supported !== null) {
                foreach ($inventory->projects as $path => $project) {
                    $directory = $root . (dirname($path) === '.' ? '' : '/' . dirname($path));
                    $validated = $this->source($report, $path, 'validation', 'composer', static fn() => $composer->validate($directory, $project['classification']['kind'] === 'locked'));
                    if ($validated !== null) {
                        $report->add($validated->exitCode === 0 ? 'evidence' : 'finding', $path, 'validation', 'composer', $validated->exitCode === 0 ? 'Composer validated the manifest and owned lock.' : 'Composer reported manifest or lock inconsistencies.', ['exitCode' => $validated->exitCode, 'output' => $validated->stdout . $validated->stderr]);
                    }
                    if (!$localOnly && $project['classification']['kind'] === 'locked') {
                        $audit = $this->source($report, $path, 'advisories', 'composer', static fn(): array => $composer->audit($directory));
                        if ($audit !== null) {
                            $report->add(array_filter(array_diff_key($audit, ['unreachable-repositories' => true, 'scope' => true])) === [] ? 'evidence' : 'finding', $path, 'advisories', 'composer', isset($audit['scope']) ? 'Owned lock contains no auditable packages; Composer skipped advisory collection.' : 'Fresh locked advisory, abandonment and policy assessment.', $audit);
                            if (!empty($audit['unreachable-repositories'])) {
                                $report->add('gap', $path, 'advisories', 'composer', 'Composer reported unreachable advisory or policy repositories.', ['unreachable' => $audit['unreachable-repositories']]);
                            }
                        }
                        $outdated = $this->source($report, $path, 'outdated', 'composer', static fn(): array => $composer->outdated($directory));
                        if ($outdated !== null) {
                            $report->add($outdated['locked'] === [] ? 'evidence' : 'finding', $path, 'outdated', 'composer', isset($outdated['scope']) ? 'Owned lock contains no packages; Composer reported an empty outdated view.' : 'Fresh Composer locked outdated view; independent stable metadata is assessed separately.', $outdated);
                        }
                    }
                }
            } else {
                $report->add('gap', 'repository', 'composer-projects', 'composer', 'Project validation and requested Composer online evidence were not performed because the interface is unavailable.');
            }
            if (!$localOnly) {
                $this->online($inventory, $runtimes, $report);
                $this->composerImages($root, $home, $inventory, $report);
            }
        } catch (\Throwable $error) {
            $report->add('gap', 'repository', 'collection', 'tooling', $error->getMessage());
        } finally {
            if (is_dir($home)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $entry) {
                    if ($entry->isDir() && !$entry->isLink()) {
                        rmdir($entry->getPathname());
                    } else {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($home);
            }
        }
        return $report;
    }

    /** @param array<string,list<array<string,mixed>>> $runtimes
     */
    private function online(Inventory $inventory, array $runtimes, Report $report): void
    {
        $upstream = new UpstreamEvidence($this->executor, $this->configuration->budgets->seconds('process'), ComposerEvidence::environment($this->environment));
        $requests = [];
        foreach ($inventory->projects as $path => $project) {
            $lockedByName = [];
            foreach (['packages', 'packages-dev'] as $section) {
                foreach ($project['lock'][$section] ?? [] as $package) {
                    if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                        $report->add('gap', $path, 'locked-stable-releases', 'lock', 'Locked package name or version is malformed.', ['section' => $section]);
                        continue;
                    }
                    $lockedByName[$package['name']][] = ['package' => $package, 'section' => $section];
                }
            }
            $directNames = [];
            foreach ($project['requirements'] as $requirement) {
                $name = $requirement['name'];
                $directNames[$name] = true;
                if (!str_contains($name, '/') || in_array($name, $inventory->policy['internalPackages'] ?? [], true)) {
                    continue;
                }
                $locked = $lockedByName[$name][0] ?? null;
                $requests[$name][] = ['path' => $path, ...$requirement, 'ownership' => 'direct', 'locked' => $locked['package'] ?? null, 'lockedSection' => $locked['section'] ?? null];
            }
            foreach ($lockedByName as $name => $lockedEntries) {
                if (isset($directNames[$name]) || !str_contains($name, '/') || in_array($name, $inventory->policy['internalPackages'] ?? [], true)) {
                    continue;
                }
                foreach ($lockedEntries as $locked) {
                    $requests[$name][] = ['path' => $path, 'section' => $locked['section'], 'constraint' => null, 'ownership' => 'locked-transitive', 'locked' => $locked['package'], 'lockedSection' => $locked['section']];
                }
            }
        }
        foreach ($inventory->policy['patches'] ?? [] as $fix) {
            $requests[$fix['package']] ??= [];
        }
        foreach ($requests as $name => $owners) {
            $url = 'https://repo.packagist.org/p2/' . $name . '.json';
            $releases = $this->source($report, $name, 'stable-releases', $url, static function () use ($upstream, $url, $name): array {
                $payload = json_decode($upstream->fetch($url), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \RuntimeException('Invalid Packagist response.');
                }
                return UpstreamEvidence::releases($payload, $name);
            });
            if ($releases === null) {
                continue;
            }
            $latest = $releases[0] ?? null;
            if ($latest === null) {
                $report->add('gap', $name, 'stable-releases', $url, 'No stable upstream release could be established.');
                continue;
            }
            foreach ($owners as $owner) {
                $locked = $owner['locked'];
                $eligibility = $owner['constraint'] === null ? null : $this->source($report, $owner['path'], 'constraint', $url, static fn(): bool => Semver::satisfies($latest['version'], $owner['constraint']));
                $current = $locked['version'] ?? null;
                $newer = $current !== null && (str_starts_with($current, 'dev-') || version_compare(ltrim($latest['version'], 'v'), ltrim($current, 'v'), '>'));
                $report->add($newer || $eligibility === false ? 'finding' : 'evidence', $owner['path'], 'stable-releases', $url, 'Independent latest stable release; installability requires solver evidence.', ['package' => $name, 'ownership' => $owner['ownership'], 'section' => $owner['section'], 'lockedSection' => $owner['lockedSection'], 'constraint' => $owner['constraint'], 'constraintEligible' => $eligibility, 'latest' => $latest, 'lockedVersion' => $current, 'lockedReference' => $locked['source']['reference'] ?? null]);
            }
            foreach ([...array_values($inventory->policy['patches'] ?? []), ...array_map(static fn(string $package, array $fix): array => ['package' => $package, ...$fix], array_keys($inventory->policy['forks'] ?? []), array_values($inventory->policy['forks'] ?? []))] as $fix) {
                if (($fix['package'] ?? null) !== $name) {
                    continue;
                }
                $reviewed = ltrim($fix['reviewedVersion'] ?? '', 'v');
                $reference = $fix['reviewedReference'] ?? '';
                if (!is_string($latest['reference'] ?? null) || $latest['reference'] === '') {
                    $report->add('gap', $name, 'upstream-reference', $url, 'Stable upstream source reference is unavailable.');
                }
                $sameVersion = $reviewed === ltrim($latest['version'], 'v');
                $sameReference = $reference === ($latest['reference'] ?? null);
                $report->add($sameVersion && $sameReference ? 'evidence' : 'finding', $name, 'upstream-reference', $url, $sameVersion && $sameReference ? 'Reviewed stable metadata agrees; patch necessity still requires behavior proof.' : 'Stable upstream version or source reference changed; review the local fix.', ['reviewedVersion' => $reviewed, 'reviewedReference' => $reference, 'latest' => $latest]);
            }
        }
        foreach ($inventory->policy['runtimes'] ?? [] as $name => $runtime) {
            $url = $runtime['source'];
            $latest = $this->source($report, $name, 'runtime-stable', $url, static fn(): string => UpstreamEvidence::runtimeVersion($name, $upstream->fetch($url)));
            if ($latest === null) {
                continue;
            }
            foreach ($runtimes[$name] ?? [] as $declaration) {
                $report->add(version_compare($latest, $declaration['version'], '>') ? 'finding' : 'evidence', $declaration['path'], 'runtime-stable', $url, 'Compared source declaration with independent latest stable runtime.', ['declared' => $declaration['version'], 'latest' => $latest]);
            }
        }
    }

    private function composerImages(string $root, string $home, Inventory $inventory, Report $report): void
    {
        $declarations = $inventory->policy['runtimes']['composer']['declarations'] ?? [];
        if ($declarations === []) {
            return;
        }
        $images = [];
        foreach ($declarations as $declaration) {
            $path = $declaration['path'];
            $reference = $this->source($report, $path, 'composer-image-php', 'source', static function () use ($root, $path): string {
                $text = file_get_contents($root . '/' . $path);
                if ($text === false || preg_match_all('/composer:\d+\.\d+\.\d+@sha256:[a-f0-9]{64}/', $text, $matches) !== 1) {
                    throw new \RuntimeException('Exactly one digest-pinned Composer image declaration is required.');
                }
                return $matches[0][0];
            });
            if ($reference !== null) {
                $images[$reference][] = $path;
            }
        }
        if ($images === []) {
            return;
        }
        $target = $this->source($report, 'repository', 'docker-target', 'docker', fn() => (new TargetResolver($this->executor, $this->environment))->resolve($this->configuration->docker, $home . '/docker'));
        if ($target === null) {
            $report->add('gap', 'repository', 'composer-image-php', 'docker', 'Composer registry image metadata could not be collected without a selected Docker transport.');
            return;
        }
        $collector = new DockerEvidence(new Client($this->executor, $target, $this->environment), $this->configuration->budgets->seconds('process'));
        foreach ($images as $reference => $paths) {
            $platforms = $this->source($report, $reference, 'composer-image-php', 'registry', static fn(): array => $collector->composerImage($reference));
            if ($platforms === null) {
                continue;
            }
            $minimum = $inventory->policy['runtimes']['php']['minimum'] ?? null;
            if (!is_string($minimum)) {
                $report->add('gap', $reference, 'composer-image-php', 'policy', 'PHP minimum is unavailable for Composer image metadata comparison.');
                continue;
            }
            foreach ($platforms as $platform => $versions) {
                $report->add(version_compare($versions['php'], $minimum, '<') ? 'finding' : 'evidence', $reference, 'composer-image-php', 'registry', 'Compared embedded PHP registry metadata with the supported minimum; no image was pulled or executed.', ['paths' => $paths, 'platform' => $platform, 'minimum' => $minimum, ...$versions]);
            }
        }
    }

    private function source(Report $report, string $project, string $kind, string $source, callable $collect): mixed
    {
        if ($this->stopped) {
            $report->add('gap', $project, $kind, $source, 'Requested evidence was not performed after workflow cancellation or expiration.');
            return null;
        }
        try {
            return $collect();
        } catch (\Throwable $error) {
            if ($error instanceof CommandFailure && ($error->result?->cancelled || $error->result === null && ($error->getCode() === 124 || $error->getCode() >= 128))) {
                $this->stopped = true;
            }
            $report->add('gap', $project, $kind, $source, $error->getMessage());
            return null;
        }
    }
}
