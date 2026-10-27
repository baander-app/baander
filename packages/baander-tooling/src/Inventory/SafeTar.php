<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

final class SafeTar
{
    public static function extract(string $archive, string $root, bool $allowAbsoluteLinks = true): void
    {
        self::destination($root);
        if (is_dir($root) && array_diff(scandir($root) ?: [], ['.', '..']) !== []) {
            throw new InventoryError('export destination must be empty');
        }
        $stream = fopen($archive, 'rb');
        if ($stream === false) {
            throw new InventoryError('Cannot open archive');
        }
        try {
            $entries = self::scan($stream, $allowAbsoluteLinks);
            foreach ($entries as $name => $entry) {
                $name = (string) $name;
                self::destination($root . '/' . $name);
                $parent = dirname($name);
                while ($parent !== '.' && $parent !== '') {
                    if (isset($entries[$parent]) && $entries[$parent]['type'] !== '5') {
                        throw new InventoryError('archive parent is not a directory: ' . $name);
                    }
                    $parent = dirname($parent);
                }
                if ($entry['type'] === '1') {
                    self::hardlink($entry['link'], $entries, []);
                }
            }
            ApplicationInventory::directory($root);
            foreach ($entries as $name => $entry) {
                $name = (string) $name;
                $target = $root . '/' . $name;
                if ($entry['type'] === '5') {
                    ApplicationInventory::directory($target);
                } elseif ($entry['type'] === '0') {
                    ApplicationInventory::directory(dirname($target));
                    $destination = fopen($target, 'xb');
                    if ($destination === false) {
                        throw new InventoryError('Cannot create archive destination: ' . $name);
                    }
                    try {
                        if (fseek($stream, $entry['offset']) !== 0 || stream_copy_to_stream($stream, $destination, $entry['size']) !== $entry['size']) {
                            throw new InventoryError('Truncated archive: ' . $name);
                        }
                    } finally {
                        fclose($destination);
                    }
                    if (!chmod($target, $entry['mode'])) {
                        throw new InventoryError('Cannot set archive file mode: ' . $name);
                    }
                }
            }
            foreach ($entries as $name => $entry) {
                $name = (string) $name;
                if ($entry['type'] !== '1') {
                    continue;
                }
                ApplicationInventory::directory(dirname($root . '/' . $name));
                $source = self::hardlink($entry['link'], $entries, []);
                if (!copy($root . '/' . $source, $root . '/' . $name) || !chmod($root . '/' . $name, $entries[$source]['mode'])) {
                    throw new InventoryError('Cannot copy archive hardlink');
                }
            }
            foreach ($entries as $name => $entry) {
                $name = (string) $name;
                if ($entry['type'] !== '2') {
                    continue;
                }
                ApplicationInventory::directory(dirname($root . '/' . $name));
                if (!symlink($entry['link'], $root . '/' . $name)) {
                    throw new InventoryError('Cannot create archive symlink');
                }
            }
            $directories = array_filter($entries, static fn(array $entry): bool => $entry['type'] === '5');
            uksort($directories, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($directories as $name => $entry) {
                if (!chmod($root . '/' . $name, $entry['mode'])) {
                    throw new InventoryError('Cannot set archive directory mode: ' . $name);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private static function destination(string $path): void
    {
        while ($path !== '.' && $path !== '/') {
            if (is_link($path)) {
                throw new InventoryError('archive destination is a symlink: ' . $path);
            }
            $path = dirname($path);
        }
    }

    private static function name(string $name): string
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || in_array('..', explode('/', $name), true)) {
            throw new InventoryError('unsafe archive path: ' . $name);
        }
        return ltrim(ApplicationInventory::normalize($name), '/');
    }

    /** @param resource $stream
     * @return array<string, array{type: string, link: string, offset: int, size: int, mode: int}>
     */
    private static function scan($stream, bool $allowAbsoluteLinks): array
    {
        $entries = [];
        $global = [];
        $pax = [];
        $longName = null;
        $longLink = null;
        $archiveSize = fstat($stream)['size'];
        while (!feof($stream)) {
            $header = fread($stream, 512);
            if ($header === '' || $header === str_repeat("\0", 512)) {
                break;
            }
            if ($header === false || strlen($header) !== 512) {
                throw new InventoryError('Truncated tar header');
            }
            $check = self::number(substr($header, 148, 8));
            $sum = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)) ?: []);
            if ($check !== $sum) {
                throw new InventoryError('Invalid tar checksum');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '' && str_starts_with(substr($header, 257, 6), 'ustar')) {
                $name = $prefix . '/' . $name;
            }
            $size = self::number(substr($header, 124, 12));
            $type = $header[156] === "\0" ? '0' : $header[156];
            $link = rtrim(substr($header, 157, 100), "\0");
            $offset = ftell($stream);
            if ($offset === false || $size > $archiveSize - $offset) {
                throw new InventoryError('Truncated tar member');
            }
            if (in_array($type, ['x', 'g', 'L', 'K'], true)) {
                if ($size > 16777216) {
                    throw new InventoryError('Oversized tar metadata');
                }
                $data = $size > 0 ? fread($stream, $size) : '';
                if ($data === false || strlen($data) !== $size) {
                    throw new InventoryError('Truncated tar metadata');
                }
                if ($type === 'x') {
                    $pax = self::pax($data);
                } elseif ($type === 'g') {
                    $global = array_replace($global, self::pax($data));
                } elseif ($type === 'L') {
                    $longName = rtrim($data, "\0");
                } else {
                    $longLink = rtrim($data, "\0");
                }
            } else {
                $metadata = array_replace($global, $pax);
                foreach (array_keys($metadata) as $key) {
                    if (str_contains(strtolower($key), 'sparse')) {
                        throw new InventoryError('Sparse tar entries are unsupported');
                    }
                }
                $name = self::name($metadata['path'] ?? $longName ?? $name);
                $link = $metadata['linkpath'] ?? $longLink ?? $link;
                if (isset($metadata['size'])) {
                    if (!ctype_digit($metadata['size'])) {
                        throw new InventoryError('Invalid PAX member size');
                    }
                    $size = (int) $metadata['size'];
                    if ($size > $archiveSize - $offset) {
                        throw new InventoryError('Truncated PAX member');
                    }
                }
                if (!in_array($type, ['0', '1', '2', '3', '4', '5', '6'], true)) {
                    throw new InventoryError('unsupported archive entry type: ' . $type);
                }
                if ($name === '' && $type !== '5') {
                    throw new InventoryError('unsafe archive root entry');
                }
                if ($name !== '') {
                    if (isset($entries[$name]) && !($entries[$name]['type'] === '5' && $type === '5')) {
                        throw new InventoryError('duplicate archive path: ' . $name);
                    }
                    if ($type === '1') {
                        $link = self::name($link);
                    } elseif ($type === '2') {
                        if ($link === '' || str_contains($link, "\0")) {
                            throw new InventoryError('unsafe archive link: ' . $name);
                        }
                        if (!$allowAbsoluteLinks && str_starts_with($link, '/')) {
                            throw new InventoryError('absolute archive link is forbidden: ' . $name);
                        }
                        $parts = str_starts_with($link, '/') || dirname($name) === '.' ? [] : explode('/', dirname($name));
                        foreach (explode('/', $link) as $part) {
                            if ($part === '' || $part === '.') {
                                continue;
                            }
                            if ($part === '..') {
                                if ($parts === []) {
                                    throw new InventoryError('archive link escapes exported filesystem: ' . $name);
                                }
                                array_pop($parts);
                            } else {
                                $parts[] = $part;
                            }
                        }
                    }
                    $entries[$name] = [
                        'type' => $type,
                        'link' => $link,
                        'offset' => $offset,
                        'size' => $size,
                        'mode' => self::number(substr($header, 100, 8)) & 0777,
                    ];
                }
                $pax = [];
                $longName = $longLink = null;
            }
            if (fseek($stream, $offset + (int) (ceil($size / 512) * 512)) !== 0) {
                throw new InventoryError('Cannot seek tar member');
            }
        }
        return $entries;
    }

    private static function number(string $field): int
    {
        $value = trim($field, "\0 ");
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^[0-7]+$/D', $value)) {
            throw new InventoryError('Invalid tar numeric field');
        }
        $number = octdec($value);
        if (!is_int($number) || $number < 0) {
            throw new InventoryError('Tar numeric field overflows');
        }
        return $number;
    }

    /** @return array<string, string> */
    private static function pax(string $data): array
    {
        $values = [];
        while ($data !== '') {
            if (!preg_match('/^([1-9][0-9]*) /', $data, $match)) {
                throw new InventoryError('Invalid PAX record');
            }
            $length = (int) $match[1];
            if ($length > strlen($data) || $length <= strlen($match[0]) || $data[$length - 1] !== "\n") {
                throw new InventoryError('Invalid PAX record length');
            }
            $record = substr($data, strlen($match[0]), $length - strlen($match[0]) - 1);
            if (!str_contains($record, '=')) {
                throw new InventoryError('Invalid PAX record value');
            }
            [$key, $value] = explode('=', $record, 2);
            $values[$key] = $value;
            $data = substr($data, $length);
        }
        return $values;
    }

    /** @param array<string, array{type: string, link: string, offset: int, size: int, mode: int}> $entries
     * @param array<string, true> $seen
     */
    private static function hardlink(string $name, array $entries, array $seen): string
    {
        if (isset($seen[$name]) || !isset($entries[$name])) {
            throw new InventoryError('missing or cyclic archive hardlink: ' . $name);
        }
        $seen[$name] = true;
        $entry = $entries[$name];
        if ($entry['type'] === '0') {
            return $name;
        }
        if ($entry['type'] !== '1') {
            throw new InventoryError('archive hardlink target is not a regular file: ' . $name);
        }
        return self::hardlink($entry['link'], $entries, $seen);
    }
}
