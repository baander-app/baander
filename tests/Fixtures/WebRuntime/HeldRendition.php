<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use RuntimeException;

/**
 * An audio rendition this process holds in progress, as the encoder does: it takes the
 * rendition's exclusive lock and writes the partial file that listeners follow.
 *
 * File names follow AudioRenditionCache: `<format>-<kbps>k-<xxh64 of mtime:size>` under
 * `CONVERT_STORAGE_PATH/audio-renditions/<track>/`. If that naming changes, the server
 * no longer sees this lock and starts its own encode, and the web runtime drill fails.
 */
final class HeldRendition
{
    public string $written = '';
    /** @var resource */
    private $lock;
    private readonly string $output;
    private readonly string $partial;

    public function __construct(string $storageRoot, string $trackKey, string $sourcePath, string $format, int $kbps)
    {
        clearstatcache(true, $sourcePath);
        $name = sprintf('%s-%dk-%s', $format, $kbps, hash('xxh64', sprintf('%d:%d', filemtime($sourcePath), filesize($sourcePath))));
        $directory = rtrim($storageRoot, '/') . '/audio-renditions/' . $trackKey;
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
        $this->output = $directory . '/' . $name . '.' . $format;
        $this->partial = $this->output . '.part';
        if (file_exists($this->output) || file_exists($this->partial)) {
            throw new RuntimeException('The rendition already exists: ' . $this->output);
        }
        $lock = fopen($directory . '/' . $name . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Cannot take the rendition lock.');
        }
        $this->lock = $lock;
    }

    public function append(string $bytes): void
    {
        if (file_put_contents($this->partial, $bytes, FILE_APPEND) !== strlen($bytes)) {
            throw new RuntimeException('Cannot append to the partial rendition.');
        }
        $this->written .= $bytes;
    }

    /** Finish as the encoder does: rename the partial file into place, then release the lock. */
    public function complete(): void
    {
        if (!rename($this->partial, $this->output)) {
            throw new RuntimeException('Cannot complete the rendition.');
        }
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
    }

    /** Deterministic stand-in audio bytes; the server streams them without decoding. */
    public static function bytes(int $length, string $seed): string
    {
        $bytes = '';
        for ($block = 0; strlen($bytes) < $length; ++$block) {
            $bytes .= hash('sha256', $seed . ':' . $block, true);
        }

        return substr($bytes, 0, $length);
    }
}
