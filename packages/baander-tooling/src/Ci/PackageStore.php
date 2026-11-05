<?php

declare(strict_types=1);

namespace Baander\Tooling\Ci;

use Baander\Tooling\Filesystem\Paths;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessResult, ProcessSpec};

/**
 * Keeps CI caches and coverage baselines in the Forgejo generic package registry.
 *
 * Every file lives at `<api>/packages/<owner>/generic/<package>/latest/<file>`. Restores and
 * baseline fetches treat an absent or unreadable package as "nothing stored", so a cold cache
 * never fails a job. Saves and stores replace the previous file and fail when the upload fails.
 * The token reaches curl through its standard input, never through the command line or output.
 */
final readonly class PackageStore
{
    private const string VERSION = 'latest';

    public function __construct(
        private CommandExecutor $executor,
        private string $apiUrl,
        private string $owner,
        #[\SensitiveParameter]
        private string $token,
    ) {
        if (!preg_match('~^https?://[^\s]+$~', $apiUrl)) {
            throw new \InvalidArgumentException('The package API URL must be an HTTP or HTTPS URL.');
        }
        if ($owner === '') {
            throw new \InvalidArgumentException('The package owner is empty.');
        }
        if ($token === '' || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new \InvalidArgumentException('The package API token is empty or contains control characters.');
        }
    }

    /** @param array<string,string> $environment */
    public static function fromEnvironment(CommandExecutor $executor, array $environment): self
    {
        $repository = $environment['GITHUB_REPOSITORY'] ?? '';
        if (!str_contains($repository, '/')) {
            throw new \InvalidArgumentException('GITHUB_REPOSITORY must name the repository as <owner>/<name>.');
        }
        return new self(
            $executor,
            $environment['GITHUB_API_URL'] ?? '',
            strstr($repository, '/', true),
            $environment['FORGEJO_TOKEN'] ?? '',
        );
    }

    public function restore(string $package, string $file, string $directory): string
    {
        $target = Paths::absolutePath($directory);
        $parent = dirname($target);
        $this->directory($parent);
        $download = self::sibling($target, 'download');
        $staging = self::sibling($target, 'restore');
        try {
            $status = self::status($this->request('GET', $package, $file, ['--output', $download]));
            if ($status !== 200) {
                $this->directory($target);
                return "No {$package} archive is available (" . self::describe($status) . "); starting with an empty {$target}.";
            }
            $this->directory($staging);
            $extract = $this->executor->run(
                new ProcessSpec(argv: ['tar', '--zstd', '--extract', '--file', $download, '--directory', $staging]),
                new CompletionPolicy(null),
            );
            if ($extract->exitCode !== 0) {
                $this->directory($target);
                return "Cannot extract the {$package} archive; starting with an empty {$target}.\n" . trim($extract->stderr);
            }
            if (is_dir($target) && !@rmdir($target)) {
                throw new \RuntimeException("Restore target is not an empty directory: {$target}");
            }
            if (!@rename($staging, $target)) {
                throw new \RuntimeException("Cannot move the restored {$package} archive into {$target}.");
            }
            return "Restored {$package} into {$target}.";
        } finally {
            self::remove($download);
            self::remove($staging);
        }
    }

    public function save(string $package, string $file, string $directory): string
    {
        $source = Paths::absolutePath($directory);
        if (!is_dir($source)) {
            throw new \RuntimeException("Cannot save {$package}: {$source} is not a directory.");
        }
        $archive = sys_get_temp_dir() . '/baander-package-' . bin2hex(random_bytes(6)) . '.tar.zst';
        try {
            $this->executor->run(new ProcessSpec(argv: ['tar', '--zstd', '--create', '--file', $archive, '--directory', $source, '.']));
            $this->replace($package, $file, $archive, 'application/zstd');
            return "Saved {$source} as {$package}/{$file}.";
        } finally {
            self::remove($archive);
        }
    }

    public function fetch(string $package, string $file, string $destination): string
    {
        $path = Paths::absolutePath($destination);
        $this->directory(dirname($path));
        $download = self::sibling($path, 'download');
        try {
            $status = self::status($this->request('GET', $package, $file, ['--output', $download]));
            if ($status !== 200) {
                return "No baseline is stored as {$package}/{$file} (" . self::describe($status) . ").";
            }
            if (!@rename($download, $path)) {
                throw new \RuntimeException("Cannot write the fetched baseline to {$path}.");
            }
            return "Fetched {$package}/{$file} into {$path}.";
        } finally {
            self::remove($download);
        }
    }

    public function store(string $package, string $file, string $source): string
    {
        $path = Paths::absolutePath($source);
        if (!is_file($path)) {
            return "No baseline at {$path}; nothing stored.";
        }
        $this->replace($package, $file, $path, 'application/json');
        return "Stored {$path} as {$package}/{$file}.";
    }

    private function replace(string $package, string $file, string $path, string $contentType): void
    {
        $this->request('DELETE', $package, $file, ['--output', '/dev/null']);
        $upload = $this->request('PUT', $package, $file, [
            '--output', '/dev/null',
            '--header', 'Content-Type: ' . $contentType,
            '--upload-file', $path,
        ]);
        $status = self::status($upload);
        if ($status < 200 || $status >= 300) {
            $detail = trim($upload->stderr);
            throw new \RuntimeException(
                "Upload of {$package}/{$file} failed (" . self::describe($status) . ")." . ($detail === '' ? '' : "\n" . $detail),
            );
        }
    }

    /** @param list<string> $options */
    private function request(string $method, string $package, string $file, array $options): ProcessResult
    {
        $url = rtrim($this->apiUrl, '/') . '/packages/' . rawurlencode($this->owner) . '/generic/'
            . rawurlencode($package) . '/' . self::VERSION . '/' . rawurlencode($file);
        return $this->executor->run(
            new ProcessSpec(
                argv: [
                    'curl', '--silent', '--show-error', '--config', '-',
                    '--request', $method, '--write-out', '%{http_code}', ...$options, $url,
                ],
                stdin: 'header = "Authorization: token ' . addcslashes($this->token, '"\\') . "\"\n",
            ),
            new CompletionPolicy(null),
        );
    }

    /** The HTTP status curl reported, or 0 when no response arrived. */
    private static function status(ProcessResult $response): int
    {
        return preg_match('/^\d{3}$/', $response->stdout) ? (int) $response->stdout : 0;
    }

    private static function describe(int $status): string
    {
        return $status === 0 ? 'no response' : 'HTTP ' . $status;
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException("Cannot create directory: {$path}");
        }
    }

    /** A hidden, uniquely named path beside the target, so a rename into place stays on one filesystem. */
    private static function sibling(string $target, string $kind): string
    {
        return dirname($target) . '/.' . basename($target) . '.' . $kind . '-' . bin2hex(random_bytes(6));
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            self::remove($entry->getPathname());
        }
        @rmdir($path);
    }
}
