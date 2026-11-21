<?php

declare(strict_types=1);

namespace Baander\DevQualification\Fixtures;

final class CheckoutArchive
{
    /**
     * @param list<string> $paths
     * @param list<string> $exclusions
     */
    public static function create(Runtime $runtime, string $archive, array $paths, array $exclusions = []): void
    {
        $directory = null;
        try {
            $defaults = [];
            if (in_array('.env.example', $paths, true)) {
                $directory = sys_get_temp_dir() . '/baander-fixture-defaults-' . bin2hex(random_bytes(8));
                Runtime::require(mkdir($directory, 0700), 'Cannot reserve fixture defaults directory.');
                Runtime::require(copy($runtime->root . '/.env.example', $directory . '/.env'), 'Cannot prepare fixture environment defaults.');
                $defaults = ['-C', $directory, '.env'];
            }
            $runtime->run([
                'tar',
                ...array_map(static fn(string $path): string => '--exclude=' . $path, $exclusions),
                '-cf', $archive,
                ...$paths,
                ...$defaults,
            ], cwd: $runtime->root);
        } finally {
            if ($directory !== null) {
                if (is_file($directory . '/.env')) {
                    unlink($directory . '/.env');
                }
                rmdir($directory);
            }
        }
    }
}
