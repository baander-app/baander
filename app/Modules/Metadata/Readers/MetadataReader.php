<?php

namespace App\Modules\Metadata\Readers;

use App\Modules\Metadata\Contracts\FormatDetectorInterface;
use App\Modules\Metadata\Contracts\MetadataReaderInterface;
use App\Modules\Metadata\Contracts\Flac\FlacReaderInterface;
use App\Modules\Metadata\Exceptions\UnsupportedFormatException;
use App\Modules\Metadata\MediaMeta\MediaMeta;
use Illuminate\Support\Facades\Log;

/**
 * Unified metadata reader facade
 * Automatically detects file format and delegates to appropriate reader
 *
 * This is the main entry point for reading metadata from any supported format
 */
class MetadataReader implements MetadataReaderInterface
{
    private const string LOG_TAG = 'MetadataReader ';

    // Reader factory map (follows AlgorithmFactory pattern)
    private const READER_MAP = [
        'flac' => Flac\FlacReader::class,
        'id3' => Id3Reader::class,
        // Future: 'ogg' => Flac\OggReader::class, (OGG uses Vorbis comments)
        // Future: 'mp4' => Mp4\Mp4Reader::class,
    ];

    private string $filePath;
    private string $format;
    private ?MetadataReaderInterface $delegateReader = null;

    public function __construct(
        string $filePath,
        ?FormatDetectorInterface $detector = null
    ) {
        $this->filePath = $filePath;
        $detector = $detector ?? app(FormatDetectorInterface::class);
        $this->format = $detector->detect($filePath);

        $this->initializeDelegateReader();
    }

    private function initializeDelegateReader(): void
    {
        if ($this->format === 'unknown') {
            Log::warning(self::LOG_TAG . 'Unknown format detected', [
                'file' => $this->filePath
            ]);
            throw UnsupportedFormatException::forFile($this->filePath, $this->format);
        }

        if (!isset(self::READER_MAP[$this->format])) {
            Log::warning(self::LOG_TAG . 'No reader registered for format', [
                'format' => $this->format,
                'file' => $this->filePath
            ]);
            throw UnsupportedFormatException::noReaderAvailable($this->format);
        }

        $readerClass = self::READER_MAP[$this->format];
        $this->delegateReader = new $readerClass($this->filePath);

        Log::debug(self::LOG_TAG . 'Delegate reader initialized', [
            'format' => $this->format,
            'reader' => $readerClass,
            'file' => $this->filePath
        ]);
    }

    /**
     * Get the delegate reader for format-specific operations
     * Useful when you need FLAC-specific features like seektable
     */
    public function getDelegateReader(): MetadataReaderInterface
    {
        return $this->delegateReader;
    }

    /**
     * Get FLAC-specific reader if format is FLAC
     *
     * @return FlacReaderInterface|null
     */
    public function getFlacReader(): ?FlacReaderInterface
    {
        if ($this->format === 'flac' && $this->delegateReader instanceof FlacReaderInterface) {
            return $this->delegateReader;
        }
        return null;
    }

    // Delegate all standard methods to the appropriate reader
    // This maintains the MediaMeta facade pattern with fallback logic

    public function getTitle(): ?string
    {
        return $this->delegateReader->getTitle();
    }

    public function getArtist(): array|string|null
    {
        return $this->delegateReader->getArtist();
    }

    public function getArtists(): array
    {
        return $this->delegateReader->getArtists();
    }

    public function getAlbum(): ?string
    {
        return $this->delegateReader->getAlbum();
    }

    public function getGenre(): ?string
    {
        return $this->delegateReader->getGenre();
    }

    public function getYear(): ?string
    {
        return $this->delegateReader->getYear();
    }

    public function getTrackNumber(): ?int
    {
        return $this->delegateReader->getTrackNumber();
    }

    public function getDiscNumber(): ?int
    {
        return $this->delegateReader->getDiscNumber();
    }

    public function getComments(): array
    {
        return $this->delegateReader->getComments();
    }

    public function getComment(): ?string
    {
        return $this->delegateReader->getComment();
    }

    public function getImages(): array
    {
        return $this->delegateReader->getImages();
    }

    public function getFrontCoverImage(): ?object
    {
        return $this->delegateReader->getFrontCoverImage();
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function isValid(): bool
    {
        return $this->format !== 'unknown' && $this->delegateReader !== null;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
