<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadCoverRequest
{
    private const int MAX_SIZE = 10 * 1024 * 1024; // 10 MB

    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private ?\RuntimeException $error = null;

    private ?string $detectedMimeType = null;

    public function __construct(
        private readonly UploadedFile $file,
        private readonly MimeDetectorPortInterface $mimeDetector,
    ) {
    }

    public function validate(): bool
    {
        if ($this->file->getSize() > self::MAX_SIZE) {
            $this->error = new \RuntimeException('File size exceeds maximum of 10 MB.');

            return false;
        }

        $realPath = $this->file->getRealPath();
        if ($realPath === false) {
            $this->error = new \RuntimeException('Could not resolve uploaded file path.');

            return false;
        }

        $detectedMimeType = $this->mimeDetector->detect($realPath);
        $this->detectedMimeType = $detectedMimeType;

        if (!in_array($detectedMimeType, self::ALLOWED_MIME_TYPES, true)) {
            $this->error = new \RuntimeException(sprintf(
                'Unsupported image type "%s". Allowed: jpeg, png, webp.',
                $detectedMimeType,
            ));

            return false;
        }

        return true;
    }

    public function getError(): ?\RuntimeException
    {
        return $this->error;
    }

    public function getFile(): UploadedFile
    {
        return $this->file;
    }

    public function getMimeType(): string
    {
        return $this->detectedMimeType ?? 'application/octet-stream';
    }
}
