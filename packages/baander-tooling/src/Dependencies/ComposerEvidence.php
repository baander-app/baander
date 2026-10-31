<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;

final readonly class ComposerEvidence
{
    /**
     * @param array<string,string> $environment
     */
    public function __construct(private CommandExecutor $executor, private float $timeout, private array $environment, private string $home) {}

    public function version(): string
    {
        $result = $this->execute(['--version'], $this->home, true);
        if ($result->exitCode !== 0 || preg_match('/\AComposer version (2\.11\.1) \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\r?\n|\z)/', $result->stdout, $match) !== 1) {
            throw new \RuntimeException('Dependency evidence requires the supported Composer 2.11.1 interface.');
        }
        return $match[1];
    }

    public function validate(string $directory, bool $locked): ProcessResult
    {
        $result = $this->execute(['validate', $locked ? '--check-lock' : '--no-check-lock', '--no-check-publish', '--no-check-all', '--strict'], $directory, true);
        if (!in_array($result->exitCode, [0, 1, 2], true) || !preg_match('/(?:^|\n)(?:\.\/)?composer\.json is (?:valid(?:[ ,.][^\n]*)?|invalid, the following errors\/warnings were found:)\s*(?:\n|$)/', $result->stdout . $result->stderr)) {
            throw new \RuntimeException('Composer validation did not produce interpretable evidence.');
        }
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(string $directory): array
    {
        $result = $this->execute(['audit', '--locked', '--format=json'], $directory, false);
        $emptyDiagnostic = match ($result->exitCode) {
            0 => 'No packages - skipping audit.',
            1 => 'No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.',
            default => null,
        };
        if ($emptyDiagnostic !== null && trim($result->stdout) === '' && trim($result->stderr) === $emptyDiagnostic) {
            self::requireEmptyLock($directory);
            return ['advisories' => [], 'abandoned' => [], 'filter' => [], 'cooldown' => [], 'scope' => 'empty-lock'];
        }
        $data = self::decode($result);
        if (!in_array($result->exitCode, [0, 1], true) || !is_array($data['advisories'] ?? null) || !is_array($data['abandoned'] ?? null) || !is_array($data['filter'] ?? null) || !is_array($data['cooldown'] ?? null)) {
            throw new \RuntimeException('Unsupported Composer audit status or schema.');
        }
        foreach ($data as $key => $value) {
            if (!in_array($key, ['advisories', 'abandoned', 'ignored-advisories', 'unreachable-repositories', 'filter', 'cooldown'], true)) {
                throw new \RuntimeException('Unsupported Composer audit category: ' . $key);
            }
            if (!is_array($value)) {
                throw new \RuntimeException('Malformed Composer audit category.');
            }
        }
        foreach (['advisories', 'ignored-advisories'] as $category) {
            foreach ($data[$category] ?? [] as $package => $advisories) {
                if (!is_string($package) || !is_array($advisories) || $advisories === []) {
                    throw new \RuntimeException('Malformed Composer advisory package evidence.');
                }
                foreach ($advisories as $advisory) {
                    if (!is_array($advisory) || !is_string($advisory['advisoryId'] ?? null) || !is_string($advisory['title'] ?? null) || !is_string($advisory['affectedVersions'] ?? null)) {
                        throw new \RuntimeException('Malformed Composer advisory record.');
                    }
                }
            }
        }
        foreach ($data['abandoned'] as $package => $replacement) {
            if (!is_string($package) || $replacement !== null && !is_string($replacement)) {
                throw new \RuntimeException('Malformed Composer abandoned-package evidence.');
            }
        }
        if ($result->exitCode !== 0 && !array_filter($data)) {
            throw new \RuntimeException('Composer audit failed without advisory evidence.');
        }
        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function outdated(string $directory): array
    {
        $result = $this->execute(['outdated', '--locked', '--format=json'], $directory, false);
        if ($result->exitCode === 0 && preg_match('/^\\s*\\[\\s*\\]\\s*$/D', $result->stdout) === 1 && trim($result->stderr) === '') {
            self::requireEmptyLock($directory);
            return ['locked' => [], 'scope' => 'empty-lock'];
        }
        $data = self::decode($result);
        if (!in_array($result->exitCode, [0, 1], true) || !is_array($data['locked'] ?? null)) {
            throw new \RuntimeException('Unsupported Composer outdated status or schema.');
        }
        foreach ($data['locked'] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null) || !is_string($package['latest'] ?? null) || !in_array($package['latest-status'] ?? null, ['up-to-date', 'semver-safe-update', 'update-possible', 'cooldown'], true)) {
                throw new \RuntimeException('Malformed Composer outdated-package evidence.');
            }
        }
        if (trim($result->stderr) !== '') {
            $data['diagnostics'] = $result->stderr;
        }
        return $data;
    }

    private static function requireEmptyLock(string $directory): void
    {
        $path = $directory . '/composer.lock';
        PrivateDirectory::assertNoSymlinks($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Cannot read owned lock inventory for empty-project evidence.');
        }
        $lock = json_decode($content, flags: JSON_THROW_ON_ERROR);
        if (!$lock instanceof \stdClass || ($lock->packages ?? null) !== [] || ($lock->{'packages-dev'} ?? null) !== []) {
            throw new \RuntimeException('Composer empty-project output does not match an empty owned lock inventory.');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(array $arguments, string $directory, bool $offline): ProcessResult
    {
        $environment = self::environment($this->environment);
        $environment['COMPOSER_HOME'] = $this->home;
        $environment['COMPOSER_CACHE_DIR'] = $this->home . '/cache';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        if ($offline) {
            $environment['COMPOSER_DISABLE_NETWORK'] = '1';
        }
        $result = $this->executor->run(new ProcessSpec(
            ['composer', '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', '--no-cache', '--working-dir=' . $directory, ...$arguments],
            cwd: $directory,
            environment: $environment,
            timeout: $this->timeout,
            processGroup: true,
            replaceEnvironment: true,
        ), new CompletionPolicy(null));
        if (preg_match('/cache.*(stale|out.?of.?date)|using.*cached|could not.*(download|resolve)|failed to (download|open)|SSL.*(error|fail)|rate.limit|HTTP[^\n]*(429|50[0-9])/i', $result->stderr)) {
            throw new \RuntimeException('Composer reported unavailable or stale source evidence.');
        }
        return $result;
    }

    /**
     * @param array<string,string> $environment
     * @return array<string,string>
     */
    public static function environment(array $environment): array
    {
        return array_intersect_key($environment, array_flip(['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy', 'COMPOSER_AUTH']));
    }

    /**
     * @return array<string,mixed>
     */
    private static function decode(ProcessResult $result): array
    {
        $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Composer returned an invalid JSON object.');
        }
        return $data;
    }
}
