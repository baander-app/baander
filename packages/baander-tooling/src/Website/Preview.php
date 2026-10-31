<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class Preview
{
    public static function route(string $directory, string $request): bool
    {
        $path = parse_url($request, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '';
        $root = realpath($directory);
        $file = $root !== false && !str_contains($path, "\0") ? realpath($root . '/' . ltrim($path, '/')) : false;
        if ($root === false || $file === false || !str_starts_with($path, '/') || preg_match('~(?:^|/)\.|[\\\\]~', $path)
            || ($file !== $root && !str_starts_with($file, $root . '/'))) {
            return self::notFound($directory);
        }
        if (str_ends_with($path, '/index.html')) {
            header('Location: ' . substr($path, 0, -strlen('index.html')), true, 308);
            return true;
        }
        if (is_dir($file) && is_file($file . '/index.html')) {
            if (!str_ends_with($path, '/')) {
                header('Location: ' . $path . '/', true, 308);
                return true;
            }
            header('Content-Type: text/html; charset=utf-8');
            readfile($file . '/index.html');
            return true;
        }
        if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
            return false;
        }
        return self::notFound($directory);
    }

    private static function notFound(string $directory): bool
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        readfile($directory . '/404.html');
        return true;
    }
}
