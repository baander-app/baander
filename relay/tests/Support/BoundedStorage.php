<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class BoundedStorage
{
    public const int CAPACITY_BYTES = 134217728;
    public string $directory;
    public string $filler;
    /**
     * @var array<string,int|string>
     */

    public array $evidence;
    public function __construct(public string $root)
    {
        $this->evidence = self::validateRoot($root);
        $this->directory = $root . '/registry-' . bin2hex(random_bytes(8));
        Runtime::requireThat(mkdir($this->directory, 0700), 'Cannot create bounded storage directory.');
        $this->filler = $this->directory . '/filler';
    }
    /**
     * @return array{capacity_bytes:int,free_bytes:int,available_bytes:int}
     */
    public static function usage(string $root): array
    {
        $output = Runtime::checked(['stat', '-f', '-c', '%b %f %a %S', '--', $root]);
        $fields = $output |> trim(...) |> (static fn(string $text): array => preg_split('/\s+/', $text));
        Runtime::requireThat(
            count($fields) === 4 && array_all($fields, static fn(string $part): bool => ctype_digit($part)),
            'Invalid filesystem usage.',
        );
        [$blocks, $free, $available, $size] = array_map(intval(...), $fields);
        return [
            'capacity_bytes' => $blocks * $size,
            'free_bytes' => $free * $size,
            'available_bytes' => $available * $size,
        ];
    }
    /**
     * @return array<string,int|string>
     */
    public static function validateRoot(string $root): array
    {
        Runtime::requireThat(
            str_starts_with($root, '/') && realpath($root) === $root && is_dir($root),
            'Storage root must be an existing directory without symlinks.',
        );
        $mounts = [];
        foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
            $fields = explode(' ', $line);
            $mountpoint = preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $match): string => chr(octdec($match[1])), $fields[4]);
            if ($mountpoint === $root) {
                $separator = array_search('-', $fields, true);
                $mounts[] = [$fields[0], $fields[$separator + 1]];
            }
        }
        Runtime::requireThat(
            count($mounts) === 1 && $mounts[0][1] === 'tmpfs',
            'Storage root must be a dedicated tmpfs mount.',
        );
        $details = self::usage($root);
        Runtime::requireThat(
            $details['capacity_bytes'] > 0 && $details['capacity_bytes'] <= self::CAPACITY_BYTES,
            'Storage mount exceeds the 128 MiB capacity ceiling.',
        );
        $device = stat($root)['dev'];
        Runtime::requireThat(
            $device !== stat(dirname($root))['dev'],
            'Storage mount must have a distinct device from its parent.',
        );
        Runtime::requireThat(
            $device !== stat(sys_get_temp_dir())['dev'],
            'Fixture logs and artifacts must be outside the bounded filesystem.',
        );
        Runtime::requireThat(
            iterator_count(new \FilesystemIterator($root)) === 0,
            'Storage mount must initially be empty.',
        );
        return $details + ['mount' => $root, 'device' => $device, 'mount_id' => $mounts[0][0]];
    }
    /**
     * @return array<string,int>
     */
    public function fill(): array
    {
        $stream = fopen($this->filler, 'xb');
        Runtime::requireThat($stream !== false, 'Cannot create storage filler.');
        $written = 0;
        $failure = null;
        try {
            $chunk = str_repeat("\x00", 1048576);
            for (;;) {
                $warning = '';
                set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                    $warning = $message;
                    return true;
                });
                try {
                    $bytes = fwrite($stream, $chunk);
                } finally {
                    restore_error_handler();
                }
                if ($bytes !== false) {
                    $written += $bytes;
                }
                if ($bytes === false || $bytes === 0 || $warning !== '') {
                    Runtime::requireThat(
                        preg_match('/No space left on device|errno=28/i', $warning) === 1,
                        'Storage filler failed without ENOSPC: ' . $warning,
                    );
                    break;
                }
                Runtime::requireThat($written <= self::CAPACITY_BYTES, 'Storage filler exceeded bounded capacity.');
            }
            Runtime::requireThat(fflush($stream), 'Cannot flush storage filler.');
        } finally {
            fclose($stream);
        }
        return ['filler_errno' => 28, 'filler_bytes' => $written] + self::usage($this->root);
    }
    public function reclaim(): void
    {
        Runtime::requireThat(unlink($this->filler), 'Cannot reclaim storage filler.');
    }
    public function close(): void
    {
        Runtime::removeTree($this->directory);
    }
}
