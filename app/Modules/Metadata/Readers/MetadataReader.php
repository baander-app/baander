<?php

namespace App\Modules\Metadata\Readers;

use App\Modules\Metadata\Contracts\FormatDetectorInterface;
use App\Modules\Metadata\Contracts\MetadataReaderInterface;
use App\Modules\Metadata\Contracts\PictureInterface;
use App\Modules\Metadata\Contracts\Flac\FlacReaderInterface;
use App\Modules\Metadata\Exceptions\UnsupportedFormatException;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Unified metadata reader facade
 * Automatically detects file format and delegates to appropriate reader
 *
 * This is the main entry point for reading metadata from any supported format
 */
class MetadataReader implements MetadataReaderInterface
{
    private const string LOG_TAG = 'MetadataReader ';

    // Image type constants (same as ID3 APIC and FLAC METADATA_BLOCK_PICTURE)
    public const IMAGE_OTHER = 0;
    public const IMAGE_FILE_ICON = 1;
    public const IMAGE_OTHER_FILE_ICON = 2;
    public const IMAGE_COVER_FRONT = 3;
    public const IMAGE_COVER_BACK = 4;
    public const IMAGE_LEAFLET = 5;
    public const IMAGE_MEDIA = 6;
    public const IMAGE_LEAD_ARTIST = 7;
    public const IMAGE_ARTIST = 8;
    public const IMAGE_CONDUCTOR = 9;
    public const IMAGE_BAND = 10;
    public const IMAGE_COMPOSER = 11;
    public const IMAGE_LYRICIST = 12;
    public const IMAGE_RECORDING_LOCATION = 13;
    public const IMAGE_DURING_RECORDING = 14;
    public const IMAGE_DURING_PERFORMANCE = 15;
    public const IMAGE_SCREEN_CAPTURE = 16;
    public const IMAGE_FISH = 17;
    public const IMAGE_ILLUSTRATION = 18;
    public const IMAGE_BAND_LOGO = 19;
    public const IMAGE_PUBLISHER_LOGO = 20;

    private ?FFMpeg $ffmpeg = null;
    private StreamCollection|null $streamCollection = null;

    // Reader factory map (follows AlgorithmFactory pattern)
    private const READER_MAP = [
        'flac' => Flac\FlacReader::class,
        'id3' => Id3\Id3Reader::class,
        'ogg' => Flac\OggReader::class,  // OGG uses Vorbis comments
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

    /**
     * Get ID3-specific reader if format is ID3
     *
     * @return MetadataReaderInterface|null
     */
    public function getId3Reader(): ?MetadataReaderInterface
    {
        if ($this->format === 'id3') {
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

    public function getFrontCoverImage(): ?PictureInterface
    {
        return $this->delegateReader->getFrontCoverImage();
    }

    /**
     * Get the first image (any type) from the file
     */
    public function getImage(): ?PictureInterface
    {
        $images = $this->getImages();
        return count($images) > 0 ? $images[0] : null;
    }

    /**
     * Get the first image of a specific type
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return PictureInterface|null The first image of the specified type, or null if not available
     */
    public function getImageByType(int $imageType): ?PictureInterface
    {
        $images = $this->getImagesByType($imageType);
        return count($images) > 0 ? $images[0] : null;
    }

    /**
     * Get all images of a specific type
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return array An array of images of the specified type
     */
    public function getImagesByType(int $imageType): array
    {
        return array_filter(
            $this->getImages(),
            fn($image) => $image->getImageType() === $imageType
        );
    }

    /**
     * Get the back cover image
     */
    public function getBackCoverImage(): ?PictureInterface
    {
        return $this->getImageByType(self::IMAGE_COVER_BACK);
    }

    /**
     * Get the artist image
     */
    public function getArtistImage(): ?PictureInterface
    {
        return $this->getImageByType(self::IMAGE_ARTIST);
    }

    /**
     * Get the image data (binary) of the first image of a specific type
     */
    public function getImageDataByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);
        return $image?->getImageData();
    }

    /**
     * Get the MIME type of the first image of a specific type
     */
    public function getImageMimeTypeByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);
        return $image?->getMimeType();
    }

    /**
     * Get the description of the first image of a specific type
     */
    public function getImageDescriptionByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);
        return $image?->getDescription();
    }

    /**
     * Get the image type of the first image
     */
    public function getImageType(): ?int
    {
        $image = $this->getImage();
        return $image?->getImageType();
    }

    /**
     * Get the image data (binary) of the front cover
     */
    public function getFrontCoverImageData(): ?string
    {
        return $this->getImageDataByType(self::IMAGE_COVER_FRONT);
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

    public function probeLength()
    {
        $stream = $this->getStreams()->first();

        if (!$stream) {
            return 0;
        }

        return (float)$stream->get('duration');
    }

    public function getMimeType(): string
    {
        return new \finfo(FILEINFO_MIME_TYPE)->file($this->filePath);
    }

    public function isAudioFile(): bool
    {
        return Str::startsWith($this->getMimeType(), 'audio/');
    }

    private function getStreams()
    {
        if (!$this->ffmpeg) {
            $this->ffmpeg = FFMpeg::create();
        }

        if ($this->streamCollection === null) {
            $this->streamCollection = $this->ffmpeg->getFFProbe()->streams($this->filePath);
        }

        return $this->streamCollection;
    }
}
