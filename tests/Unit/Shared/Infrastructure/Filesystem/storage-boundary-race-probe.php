<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Filesystem {
    // This function exists only in the child process and forces a real publication
    // between the boundary's failed realpath lookup and its existence check.
    /** @phpstan-impure */
    function realpath(string $path): string|false
    {
        $resolved = \realpath($path);
        $race = &$GLOBALS['storageBoundaryRace'];
        if ($path !== $race['cursor']) {
            return $resolved;
        }
        ++$race['lookups'];
        if ($resolved !== false || !$race['armed']) {
            return $resolved;
        }
        $race['armed'] = false;
        $race['triggered'] = true;
        $published = match ($race['mode']) {
            'create', 'non-directory' => \file_put_contents($path, 'published') !== false,
            'rename' => \rename($race['partial'], $path),
            'external-link' => \symlink($race['outside'], $path),
            'dangling-link' => \symlink($race['outside'] . '/missing', $path),
            default => throw new \RuntimeException('Unknown publication mode.'),
        };
        if (!$published) {
            throw new \RuntimeException('Unable to publish the diagnostic target.');
        }
        return $resolved;
    }
}

namespace {
use App\Shared\Infrastructure\Filesystem\StoragePathBoundary;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

    $mode = $_SERVER['argv'][1] ?? throw new RuntimeException('Missing publication mode.');
    $root = sys_get_temp_dir() . '/baander-storage-boundary-' . bin2hex(random_bytes(8));
    $base = $root . '/cache';
    $outside = $root . '/outside';
    mkdir($base, 0700, true);
    mkdir($outside, 0700);
    $cursor = $base . '/rendition';
    $partial = $base . '/partial';
    $candidate = $mode === 'non-directory' ? $cursor . '/child' : $cursor;
    $existing = str_starts_with($mode, 'existing-');
    $GLOBALS['storageBoundaryRace'] = [
        'mode' => $mode, 'cursor' => $cursor, 'outside' => $outside,
        'partial' => $partial, 'armed' => !$existing, 'triggered' => false, 'lookups' => 0,
    ];
    try {
        if ($mode === 'rename') {
            file_put_contents($partial, 'published');
        } elseif ($existing) {
            symlink($outside . ($mode === 'existing-dangling-link' ? '/missing' : ''), $cursor);
        }
        $result = null;
        $error = null;
        try {
            $result = StoragePathBoundary::resolve($base, $candidate);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        echo json_encode([
            'triggered' => $GLOBALS['storageBoundaryRace']['triggered'],
            'lookups' => $GLOBALS['storageBoundaryRace']['lookups'],
            'resolved' => $result === $candidate,
            'error' => $error === null ? null : explode(': ', $error, 2)[0],
        ], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        foreach ([$cursor, $partial] as $path) {
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }
        }
        rmdir($base);
        rmdir($outside);
        rmdir($root);
    }
}
