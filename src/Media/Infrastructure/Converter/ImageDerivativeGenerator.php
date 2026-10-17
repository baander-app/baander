<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

use App\Media\Application\Port\ImageConversionPortInterface;
use App\Media\Application\Port\ImageDerivativePortInterface;
use App\Media\Application\Port\StoragePortInterface;

final readonly class ImageDerivativeGenerator implements ImageDerivativePortInterface
{
    public function __construct(private StoragePortInterface $storage, private ImageConversionPortInterface $converter)
    {
    }

    public function generate(string $sourcePath, ?string $preset): void
    {
        $source = $this->storage->resolve($sourcePath);
        $destination = dirname($source) . '/' . pathinfo($source, PATHINFO_FILENAME)
            . ($preset === null ? '' : '_' . $preset) . '.webp';
        $lock = fopen($destination . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open image derivative lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire image derivative lock.');
            }
            clearstatcache(true, $destination);
            if (is_file($destination)) {
                return;
            }
            if ($preset === null) {
                $this->converter->convertToWebp($source, dirname($source));
            } else {
                $this->converter->convertPreset($source, dirname($source), $preset);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
