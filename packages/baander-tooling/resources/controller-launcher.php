<?php

declare(strict_types=1);

// No generation code may load before its shared lease is held.
(static function (): void {
    $root = __DIR__;
    $assertPath = static function (string $path): void {
        for ($part = $path; $part !== dirname($part); $part = dirname($part)) {
            if (is_link($part)) {
                throw new RuntimeException('Refusing symlink controller path.');
            }
        }
    };
    $lock = static function (string $path, int $mode) use ($assertPath) {
        $assertPath($path);
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            $assertPath($path);
            $stream = @fopen($path, 'r+b');
        }
        if ($stream === false) {
            throw new RuntimeException('Cannot open controller lock.');
        }
        clearstatcache(true, $path);
        $info = lstat($path);
        $opened = fstat($stream);
        if ($info === false || $opened === false || ($info['mode'] & 0170000) !== 0100000 || $info['dev'] !== $opened['dev'] || $info['ino'] !== $opened['ino'] || !flock($stream, $mode)) {
            fclose($stream);
            throw new RuntimeException('Cannot acquire controller lock.');
        }
        return $stream;
    };
    $publication = $lock($root . '/publication.lock', LOCK_EX);
    try {
        $assertPath($root . '/selected.json');
        $selector = json_decode((string) file_get_contents($root . '/selected.json'), true, flags: JSON_THROW_ON_ERROR);
        $id = $selector['generation'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid controller generation selector.');
        }
        $entrypoint = $root . '/generations/' . $id . '/scripts/dev-environment.php';
        $assertPath($entrypoint);
        if (!is_file($entrypoint)) {
            throw new RuntimeException('Selected controller generation is incomplete.');
        }
        $lease = $lock($root . '/leases/' . $id . '.lock', LOCK_SH);
        // Global resources survive shutdown callbacks and object destructors; PHP
        // closes the stream during final resource cleanup when the invocation ends.
        $GLOBALS['baanderControllerGenerationLease'] = $lease;
    } finally {
        fclose($publication);
    }
    global $argv, $argc;
    require $entrypoint;
})();
