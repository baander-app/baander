<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

final class Environment
{
    public static function absolutePath(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = (getenv('HOME') ?: '') . substr($path, 1);
        }
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return '/' . implode('/', $parts);
    }

    public static function resolvedPath(string $path): string
    {
        $path = self::absolutePath($path);
        if (($resolved = realpath($path)) !== false) {
            return $resolved;
        }
        if ($path === '/') {
            return $path;
        }
        return rtrim(self::resolvedPath(dirname($path)), '/') . '/' . basename($path);
    }

    public static function previewId(string $worktree, string $repository): string
    {
        return 'preview-' . substr(hash('sha256', $repository . "\0" . self::resolvedPath($worktree)), 0, 16);
    }

    /** @param array<string,string>|null $source
     * @return array<string,string>
     */
    public static function clean(?array $source = null): array
    {
        $allowed = [
            'PATH',
            'HOME',
            'USER',
            'LOGNAME',
            'LANG',
            'LC_ALL',
            'LC_CTYPE',
            'SSH_AUTH_SOCK',
            'XDG_RUNTIME_DIR',
            'DBUS_SESSION_BUS_ADDRESS',
            'SSL_CERT_FILE',
            'SSL_CERT_DIR',
            'HTTP_PROXY',
            'HTTPS_PROXY',
            'NO_PROXY',
            'http_proxy',
            'https_proxy',
            'no_proxy',
        ];
        return array_intersect_key($source ?? getenv(), array_flip($allowed));
    }

    public static function executable(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/bin:/bin') as $directory) {
            $candidate = $directory . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return self::absolutePath($candidate);
            }
        }
        return null;
    }
}
