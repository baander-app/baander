<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

final class Environment
{
    public static function previewId(string $worktree, string $repository): string
    {
        return 'preview-' . substr(hash('sha256', $repository . "\0" . \Baander\Tooling\Filesystem\Paths::resolvedPath($worktree)), 0, 16);
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
                return \Baander\Tooling\Filesystem\Paths::absolutePath($candidate);
            }
        }
        return null;
    }
}
