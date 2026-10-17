<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\WebRuntime;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use RuntimeException;

/** Supplies accepted generation-local bytes through the real audio runtime port for delivery drills. */
final class HeldRendition
{
    public string $written = '';
    /** @var resource */
    private $lock;
    private readonly string $output;
    private readonly string $partial;
    private readonly AudioRenditionClaim $claim;

    public function __construct(
        string $storageRoot,
        string $trackKey,
        string $sourcePath,
        string $format,
        int $kbps,
        private readonly AudioRenditionRuntimePort $runtime,
    ) {
        clearstatcache(true, $sourcePath);
        $audioFormat = AudioRenditionFormat::from($format);
        $bitrate = $audioFormat->supportedBitrate($kbps * 1000);
        $signature = $audioFormat->sourceSignature($bitrate, (int) filemtime($sourcePath), (int) filesize($sourcePath));
        $paths = new SegmentFileResolver($storageRoot);
        $input = new AudioRenditionInput($trackKey, $signature, $sourcePath, $audioFormat, $bitrate, $paths->resolveAudioRenditionPath($trackKey, $signature));
        $admitted = $runtime->admit($input);
        $worker = new MediaWorkerIdentity('baander.app:web-delivery-fixture', bin2hex(random_bytes(16)), 'fixture', 1);
        $this->claim = $runtime->claim($admitted->key, $worker, 3600)
            ?? throw new RuntimeException('Cannot claim the disposable delivery rendition.');
        $directory = $this->claim->runtime->generationDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
        $this->output = $directory . '/' . $signature;
        $this->partial = $this->output . SegmentFileResolver::PARTIAL_SUFFIX;
        $lock = fopen($input->outputDirectory . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Cannot take the rendition lock.');
        }
        $this->lock = $lock;
        if (!$runtime->start($this->claim, $this->partial, $this->output)) {
            throw new RuntimeException('Cannot publish the disposable delivery generation.');
        }
    }

    public function append(string $bytes): void
    {
        if (!$this->runtime->renew($this->claim, 3600)) {
            throw new RuntimeException('The delivery fixture lost its accepted generation.');
        }
        if (file_put_contents($this->partial, $bytes, FILE_APPEND) !== strlen($bytes)) {
            throw new RuntimeException('Cannot append to the partial rendition.');
        }
        $this->written .= $bytes;
    }

    /** Atomically publish the completed file and durable completion under the accepted claim. */
    public function complete(): void
    {
        if (!$this->runtime->withOwnership($this->claim, function (): void {
            if (!rename($this->partial, $this->output) || !$this->runtime->complete($this->claim)) {
                throw new RuntimeException('Cannot complete the rendition.');
            }
        })) {
            throw new RuntimeException('The delivery fixture lost publication ownership.');
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
