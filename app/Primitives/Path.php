<?php

namespace App\Primitives;

use App\Primitives\Traits\ForwardsCalls;
use App\Primitives\Traits\ImmutableBuilder;
use JsonSerializable;
use Stringable;

/**
 * Immutable path manipulation with fluent builder pattern.
 *
 * All dynamic methods can be called statically where the first
 * argument becomes the path value: Path::normalize('/foo/./bar') → Path('/foo/bar')
 *
 * @method self join(string ...$parts) Join path segments
 * @method self normalize() Normalize path (resolve . and ..)
 * @method self ensureTrailingSlash() Ensure trailing slash
 * @method self stripTrailingSlash() Strip trailing slash
 * @method self parent() Get parent directory
 * @method self withExtension(string $extension) Change file extension
 * @method string|null resolve() Resolve to absolute path
 * @method string basename() Get basename
 * @method string dirname() Get parent directory
 * @method string extension() Get lowercase extension
 * @method string filename() Get filename without extension
 * @method bool isAbsolute() Is absolute path
 * @method bool isRelative() Is relative path
 * @method bool exists() File/directory exists
 * @method bool isFile() Is regular file
 * @method bool isDirectory() Is directory
 * @method bool isReadable() Is readable
 * @method bool isWritable() Is writable
 * @method int size() File size in bytes
 * @method array glob() Glob pattern match
 *
 * @method static self join(string $path, string ...$parts) Join path segments
 * @method static self normalize(string $path) Normalize path
 * @method static self ensureTrailingSlash(string $path) Ensure trailing slash
 * @method static self stripTrailingSlash(string $path) Strip trailing slash
 * @method static self parent(string $path) Get parent directory
 * @method static self withExtension(string $path, string $extension) Change file extension
 * @method static string|null resolve(string $path) Resolve to absolute path
 * @method static string basename(string $path) Get basename
 * @method static string dirname(string $path) Get parent directory
 * @method static string extension(string $path) Get lowercase extension
 * @method static string filename(string $path) Get filename without extension
 * @method static bool isAbsolute(string $path) Is absolute path
 * @method static bool isRelative(string $path) Is relative path
 * @method static bool exists(string $path) File/directory exists
 * @method static bool isFile(string $path) Is regular file
 * @method static bool isDirectory(string $path) Is directory
 * @method static bool isReadable(string $path) Is readable
 * @method static bool isWritable(string $path) Is writable
 * @method static int size(string $path) File size in bytes
 * @method static array glob(string $path) Glob pattern match
 */
class Path implements Stringable, JsonSerializable
{
    use ForwardsCalls;
    use ImmutableBuilder;

    protected function __construct(protected string $path)
    {
    }

    public static function make(string $path): static
    {
        return new static($path);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function value(): string
    {
        return $this->path;
    }

    // ─── Interfaces ─────────────────────────────────────────────────────────────

    public function __toString(): string
    {
        return $this->path;
    }

    public function jsonSerialize(): string
    {
        return $this->path;
    }

    // ─── Magic Methods ──────────────────────────────────────────────────────────

    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (count($parameters) === 0) {
            throw new \BadMethodCallException("Method {$method}() requires at least one argument on " . static::class);
        }

        return static::make(array_shift($parameters))->{$method}(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        $impl = 'do' . ucfirst($method);

        if (! method_exists($this, $impl)) {
            static::throwBadMethodCallException($method);
        }

        return $this->$impl(...$parameters);
    }

    // ─── Private Implementation ──────────────────────────────────────────────────

    // ── Builders ────────────────────────────────────────────────────────────────

    private function doJoin(string ...$parts): static
    {
        return $this->clone()->withPath(self::computeJoin($this->path, ...$parts));
    }

    private function doNormalize(): static
    {
        return $this->clone()->withPath(self::computeNormalize($this->path));
    }

    private function doEnsureTrailingSlash(): static
    {
        return $this->clone()->withPath(self::computeEnsureTrailingSlash($this->path));
    }

    private function doStripTrailingSlash(): static
    {
        return $this->clone()->withPath(self::computeStripTrailingSlash($this->path));
    }

    private function doParent(): static
    {
        return $this->clone()->withPath(dirname($this->path));
    }

    private function doWithExtension(string $extension): static
    {
        $extension = ltrim($extension, '.');

        $dir = dirname($this->path);
        $filename = pathinfo($this->path, PATHINFO_FILENAME);

        $newPath = ($dir === '.')
            ? $filename . '.' . $extension
            : $dir . '/' . $filename . '.' . $extension;

        return $this->clone()->withPath($newPath);
    }

    // ── Inspectors ─────────────────────────────────────────────────────────────

    private function doResolve(): ?string
    {
        $real = realpath($this->path);

        return $real === false ? null : $real;
    }

    private function doBasename(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    private function doDirname(): string
    {
        return dirname($this->path);
    }

    private function doExtension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    private function doFilename(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    private function doIsAbsolute(): bool
    {
        return str_starts_with($this->path, '/');
    }

    private function doIsRelative(): bool
    {
        return ! $this->doIsAbsolute();
    }

    private function doExists(): bool
    {
        return file_exists($this->path);
    }

    private function doIsFile(): bool
    {
        return is_file($this->path);
    }

    private function doIsDirectory(): bool
    {
        return is_dir($this->path);
    }

    private function doIsReadable(): bool
    {
        return is_readable($this->path);
    }

    private function doIsWritable(): bool
    {
        return is_writable($this->path);
    }

    private function doSize(): int
    {
        return @filesize($this->path) ?: 0;
    }

    private function doGlob(): array
    {
        $result = glob($this->path);

        return $result === false ? [] : $result;
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────────

    private static function computeJoin(string ...$parts): string
    {
        $filtered = array_filter($parts, fn (string $part) => $part !== '');

        if ($filtered === []) {
            return '';
        }

        $joined = implode('/', $filtered);
        $joined = preg_replace('#/{2,}#', '/', $joined);

        return $joined;
    }

    private static function computeNormalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/{2,}#', '/', $path);

        $parts = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (! empty($result) && end($result) !== '..') {
                    array_pop($result);
                } else {
                    $result[] = '..';
                }
            } else {
                $result[] = $part;
            }
        }

        $normalized = implode('/', $result);

        if (str_starts_with($path, '/')) {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    private static function computeEnsureTrailingSlash(string $path): string
    {
        return str_ends_with($path, '/') ? $path : $path . '/';
    }

    private static function computeStripTrailingSlash(string $path): string
    {
        $stripped = rtrim($path, '/');

        return $stripped === '' ? '/' : $stripped;
    }

    protected function withPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }
}
