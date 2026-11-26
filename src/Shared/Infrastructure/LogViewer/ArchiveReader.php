<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer;

final readonly class ArchiveReader
{
    public function __construct(private int $maxExpandedBytes = 268435456, private int $maxMembers = 1000) {}

    /** @return list<string> */
    public function members(string $path): array
    {
        if (str_ends_with(strtolower($path), '.gz')) {
            $name = substr(basename($path), 0, -3);
            $this->validateName($name);
            return [$name];
        }
        $zip = $this->openZip($path);
        try {
            $names = [];
            $expanded = 0;
            if ($zip->numFiles > $this->maxMembers) {
                throw new \RuntimeException('Archive member limit exceeded.');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new \RuntimeException('Cannot inspect archive member.');
                }
                $name = $stat['name'];
                $this->validateName($name);
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (($system === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) || $stat['encryption_method'] !== 0) {
                    throw new \RuntimeException('Encrypted and symlink archive members are unsupported.');
                }
                $expanded += $stat['size'];
                if ($expanded > $this->maxExpandedBytes) {
                    throw new \RuntimeException('Archive expansion limit exceeded.');
                }
                if (!str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }
            if (count($names) !== count(array_unique($names))) {
                throw new \RuntimeException('Duplicate archive member names are unsupported.');
            }
            return $names;
        } finally {
            $zip->close();
        }
    }

    /** @return \Generator<int, string> */
    public function records(string $path, ?string $member = null): \Generator
    {
        $names = $this->members($path);
        if (str_ends_with(strtolower($path), '.gz')) {
            if ($member !== null && $member !== $names[0]) {
                throw new \RuntimeException('Archive member is unavailable.');
            }
            yield from $this->split($this->gzipChunks($path));
            return;
        }
        if ($member === null || !in_array($member, $names, true)) {
            throw new \RuntimeException('Select an admitted archive member.');
        }
        $zip = $this->openZip($path);
        $stream = $zip->getStream($member);
        if ($stream === false) {
            $zip->close();
            throw new \RuntimeException('Cannot read archive member.');
        }
        try {
            yield from $this->split($this->streamChunks($stream));
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    private function openZip(string $path): \ZipArchive
    {
        if (!str_ends_with(strtolower($path), '.zip')) {
            throw new \RuntimeException('Unsupported archive format.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Cannot open corrupt archive.');
        }
        return $zip;
    }

    private function validateName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/(^|\/)\.\.(\/|$)|^[A-Za-z]:/', $name) === 1 || preg_match('/\.(zip|gz|gzip|bz2|xz|7z|tar)$/i', $name) === 1) {
            throw new \RuntimeException('Unsafe or nested archive member.');
        }
    }

    /**
 * @param resource $stream
 * @return \Generator<int, string>
 */
    private function streamChunks($stream): \Generator
    {
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                throw new \RuntimeException('Archive read failed.');
            }
            $size += strlen($chunk);
            if ($size > $this->maxExpandedBytes) {
                throw new \RuntimeException('Archive expansion limit exceeded.');
            }
            yield $chunk;
        }
    }

    /** @return \Generator<int, string> */
    private function gzipChunks(string $path): \Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open archive.');
        }
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $expanded = 0;
        $compressed = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 4096);
                if ($chunk === false) {
                    throw new \RuntimeException('Archive read failed.');
                }
                $compressed += strlen($chunk);
                $output = @inflate_add($inflate, $chunk, feof($stream) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($output === false) {
                    throw new \RuntimeException('Corrupt gzip archive.');
                }
                $expanded += strlen($output);
                if ($expanded > $this->maxExpandedBytes) {
                    throw new \RuntimeException('Archive expansion limit exceeded.');
                }
                yield $output;
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    if (inflate_get_read_len($inflate) < $compressed) {
                        throw new \RuntimeException('Concatenated gzip streams and trailing bytes are unsupported.');
                    }
                    if (!feof($stream) && fread($stream, 1) !== '') {
                        throw new \RuntimeException('Concatenated gzip streams are unsupported.');
                    }
                    break;
                }
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw new \RuntimeException('Truncated gzip archive.');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param iterable<string> $chunks
     * @return \Generator<int, string>
     */
    private function split(iterable $chunks): \Generator
    {
        $line = '';
        $record = '';
        // Keep one extra byte so normalization can report truncation explicitly.
        $retained = LogNormalizer::MAX_RECORD_BYTES + 1;
        foreach ($chunks as $chunk) {
            $offset = 0;
            while ($offset < strlen($chunk)) {
                $newline = strpos($chunk, "\n", $offset);
                $end = $newline === false ? strlen($chunk) : $newline + 1;
                $lineRoom = max(0, $retained - strlen($line));
                $line .= substr($chunk, $offset, min($end - $offset, $lineRoom));
                $offset = $end;
                if ($newline === false) {
                    break;
                }
                if ($record !== '' && FileRecordReader::startsRecord($line)) {
                    yield $record;
                    $record = '';
                }
                $record .= substr($line, 0, max(0, $retained - strlen($record)));
                $line = '';
            }
        }
        if ($line !== '' && $record !== '' && FileRecordReader::startsRecord($line)) {
            yield $record;
            $record = '';
        }
        $record .= substr($line, 0, max(0, $retained - strlen($record)));
        if ($record !== '') {
            yield $record;
        }
    }
}
