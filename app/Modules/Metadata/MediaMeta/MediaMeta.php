<?php

namespace App\Modules\Metadata\MediaMeta;

use App\Modules\Metadata\MediaMeta\Frame\Apic;
use App\Modules\Metadata\MediaMeta\Frame\TIT2;
use Exception;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MediaMeta provides a unified interface for reading ID3 tags from audio files.
 * It supports both ID3v1 and ID3v2 formats and can extract image data from APIC tags.
 */
class MediaMeta
{
    // Image type constants
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
    private const string LOG_TAG = 'MediaMeta ';
    /**
     * @var string The path to the audio file
     */
    protected string $filePath;
    /**
     * @var Id3v1|null The ID3v1 tag reader
     */
    protected ?Id3v1 $id3v1 = null;
    /**
     * @var Id3v2|null The ID3v2 tag reader
     */
    protected ?Id3v2 $id3v2 = null;
    /**
     * @var int The preferred text encoding for ID3v2 tags
     */
    protected int $encoding = Encoding::UTF8;
    private ?FFMpeg $ffmpeg = null;
    private StreamCollection|null $streamCollection = null;

    /**
     * Create a new MediaMeta instance.
     *
     * @param string $filePath The path to the audio file
     * @param int $encoding The preferred text encoding for ID3v2 tags (default: UTF-8)
     */
    public function __construct(string $filePath, int $encoding = Encoding::UTF8)
    {
        $this->filePath = $filePath;
        $this->encoding = $encoding;
    }

    /**
     * Get the preferred text encoding for ID3v2 tags.
     *
     * @return int The encoding constant from Encoding
     */
    public function getEncoding(): int
    {
        return $this->encoding;
    }

    /**
     * Set the preferred text encoding for ID3v2 tags.
     *
     * @param int $encoding The encoding constant from Encoding
     * @return self
     */
    public function setEncoding(int $encoding): self
    {
        $this->encoding = $encoding;

        // Reset ID3v2 reader to apply new encoding
        $this->id3v2 = null;

        return $this;
    }

    /**
     * Get the title of the audio file.
     *
     * @return string|null The title, or null if not available
     */
    public function getTitle(): ?string
    {
        // Try ID3v2 first
        try {
            $id3v2 = $this->getID3v2();
            $frame = $id3v2->getTIT2Frame();
            if ($frame) {
                return $frame->getTitle();
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getTitle: Failed to retrieve title from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getTitle();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getTitle: Failed to retrieve title from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the ID3v2 tag reader.
     *
     * @return Id3v2 The ID3v2 tag reader, or null if the file doesn't have ID3v2 tags
     * @throws Exception
     */
    public function getID3v2(): Id3v2
    {
        $options = [
            'encoding' => $this->encoding,
        ];

        $this->id3v2 = new Id3v2($this->filePath, $options);

        return $this->id3v2;
    }

    /**
     * Get the ID3v1 tag reader.
     *
     * @return Id3v1 The ID3v1 tag reader, or null if the file doesn't have ID3v1 tags
     * @throws Exception
     */
    public function getID3v1(): Id3v1
    {
        $this->id3v1 = new Id3v1($this->filePath);

        return $this->id3v1;
    }

    /**
     * Get the title frame of the audio file.
     *
     * @return TIT2|null The title frame, or null if not available
     */
    public function getTitleFrame(): ?TIT2
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getTIT2Frame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getTitleFrame: Failed to retrieve title frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);

        }

        return null;
    }

    /**
     * Get the track number
     *
     * @return int|null
     */
    public function getTrackNumber(): ?int
    {
        try {
            $id3v2 = $this->getID3v2();
            $track = $id3v2->getTRCKFrame()->getTrack();

            if ($track) {
                $track = preg_replace('/\/.*$/', '', $track);
                return $track !== '' ? (int)$track : null;
            }

            return null;
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getTrackNumber: Failed to retrieve track number from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        // Fallback to id3v1

        try {
            $this->getID3v1();
            return $this->getID3v1()->getTrack();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getTrackNumber: Failed to retrieve track number from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }


    /**
     * Get the artist of the audio file.
     *
     * @return array|string|null The artist(s), or null if not available
     */
    public function getArtist(): array|string|null
    {
        // Try ID3v2 first
        try {
            $id3v2 = $this->getID3v2();
            $frame = $id3v2->getTpe1Frame();
            if ($frame) {
                return $frame->getArtists();
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getArtist: Failed to retrieve artist from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getArtist();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getArtist: Failed to retrieve artist from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the artists of the audio file as an array.
     *
     * @return array<string> The artists, or an empty array if not available
     */
    public function getArtists(): array
    {
        $frame = $this->getArtistFrame();
        if ($frame) {
            return $frame->getArtists();
        }

        $artist = $this->getArtist();
        if ($artist) {
            return [$artist];
        }

        return [];
    }

    /**
     * Get the artist frame of the audio file.
     *
     * @return \App\Modules\Metadata\MediaMeta\Frame\TPE1|null The artist frame, or null if not available
     */
    public function getArtistFrame(): ?\App\Modules\Metadata\MediaMeta\Frame\TPE1
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getTPE1Frame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getArtistFrame: Failed to retrieve artist frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the album of the audio file.
     *
     * @return string|null The album, or null if not available
     */
    public function getAlbum(): ?string
    {
        // Try ID3v2 first
        try {
            $id3v2 = $this->getID3v2();
            $frame = $id3v2->getTALBFrame();
            if ($frame) {
                return $frame->getAlbum();
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getAlbum: Failed to retrieve album from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getAlbum();
        } catch (Exception $e) {
            Log::error(self::LOG_TAG . 'getAlbum: Failed to retrieve album from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the album frame of the audio file.
     *
     * @return \App\Modules\Metadata\MediaMeta\Frame\TALB|null The album frame, or null if not available
     */
    public function getAlbumFrame(): ?\App\Modules\Metadata\MediaMeta\Frame\TALB
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getTALBFrame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getAlbumFrame: Failed to retrieve album frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }
        return null;
    }

    /**
     * Get the genre of the audio file.
     *
     * @return string|null The genre, or null if not available
     */
    public function getGenre(): ?string
    {
        // Try ID3v2 first
        try {
            $id3v2 = $this->getID3v2();
            $frame = $id3v2->getTCONFrame();
            if ($frame) {
                return $frame->getGenre();
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getGenre: Failed to retrieve genre from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }
        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getGenre();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getGenre: Failed to retrieve genre from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the genre frame of the audio file.
     *
     * @return \App\Modules\Metadata\MediaMeta\Frame\TCON|null The genre frame, or null if not available
     */
    public function getGenreFrame(): ?\App\Modules\Metadata\MediaMeta\Frame\TCON
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getTCONFrame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getGenreFrame: Failed to retrieve genre frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the year of the audio file.
     *
     * @return string|null The year, or null if not available
     */
    public function getYear(): ?string
    {
        // Try ID3v2 first
        try {
            $id3v2 = $this->getID3v2();
            $frame = $id3v2->getTYERFrame();
            if ($frame) {
                return $frame->getYear();
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getYear: Failed to retrieve year from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getYear();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getYear: Failed to retrieve year from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }
        return null;
    }

    /**
     * Get the year frame of the audio file.
     *
     * @return \App\Modules\Metadata\MediaMeta\Frame\TYER|null The year frame, or null if not available
     */
    public function getYearFrame(): ?\App\Modules\Metadata\MediaMeta\Frame\TYER
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getTYERFrame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getYearFrame: Failed to retrieve year frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the comments of the audio file.
     *
     * @return array<\App\Modules\Metadata\MediaMeta\Frame\COMM> The comment frames, or an empty array if not available
     */
    public function getComments(): array
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getCOMMFrames();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getComments: Failed to retrieve comment frames from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return [];
    }

    /**
     * Get the first comment of the audio file.
     *
     * @return string|null The comment text, or null if not available
     */
    public function getComment(): ?string
    {
        $frame = $this->getCommentFrame();
        if ($frame) {
            return $frame->getText();
        }

        // Fall back to ID3v1
        try {
            $id3v1 = $this->getID3v1();
            return $id3v1->getComment();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getComment: Failed to retrieve comment from ID3v1 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);

        }

        return null;
    }

    /**
     * Get the first comment frame of the audio file.
     *
     * @return \App\Modules\Metadata\MediaMeta\Frame\COMM|null The comment frame, or null if not available
     */
    public function getCommentFrame(): ?\App\Modules\Metadata\MediaMeta\Frame\COMM
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getCOMMFrame();
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getCommentFrame: Failed to retrieve comment frame from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }
        return null;
    }

    /**
     * Get a comment frame with the given description.
     *
     * @param string $description The description
     * @return \App\Modules\Metadata\MediaMeta\Frame\COMM|null The comment frame, or null if not available
     */
    public function getCommentFrameByDescription(string $description): ?\App\Modules\Metadata\MediaMeta\Frame\COMM
    {
        try {
            $id3v2 = $this->getID3v2();
            return $id3v2->getCOMMFrameByDescription($description);
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getCommentFrameByDescription: Failed to retrieve comment frame by description from ID3v2 tags', [
                'error'       => $e->getMessage(),
                'file'        => $this->filePath,
                'description' => $description,
                'exception'   => $e,
            ]);
        }

        return null;
    }

    /**
     * Get the front cover image from the audio file.
     *
     * @return Apic|null The front cover image, or null if not available
     */
    public function getFrontCoverImage(): ?Apic
    {
        return $this->getImageByType(self::IMAGE_COVER_FRONT);
    }

    /**
     * Get the first APIC frame (image) of a specific type from the audio file.
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return Apic|null The first APIC frame of the specified type, or null if not available
     */
    public function getImageByType(int $imageType): ?Apic
    {
        $images = $this->getImagesByType($imageType);

        return count($images) > 0 ? $images[0] : null;
    }

    /**
     * Get APIC frames (images) of a specific type from the audio file.
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return array<Apic> An array of APIC frames of the specified type, or an empty array if none are available
     */
    public function getImagesByType(int $imageType): array
    {
        $images = [];
        $allImages = $this->getImages();

        foreach ($allImages as $image) {
            if ($image->getImageType() === $imageType) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * Get all APIC frames (images) from the audio file.
     *
     * @return array<Apic> An array of APIC frames, or an empty array if none are available
     */
    public function getImages(): array
    {
        $images = [];

        try {
            $id3v2 = $this->getID3v2();
            $frames = $id3v2->getFramesByIdentifier('APIC');
            if (count($frames) > 0) {
                $images = $frames;
            }
        } catch (Exception $e) {
            Log::debug(self::LOG_TAG . 'getImages: Failed to retrieve image frames from ID3v2 tags', [
                'error'     => $e->getMessage(),
                'file'      => $this->filePath,
                'exception' => $e,
            ]);
        }

        return $images;
    }

    /**
     * Get the image type of the first APIC frame.
     *
     * @return int|null The image type, or null if not available
     */
    public function getImageType(): ?int
    {
        $image = $this->getImage();

        return $image?->getImageType();
    }

    /**
     * Get the first APIC frame (image) from the audio file.
     *
     * @return Apic|null The first APIC frame, or null if not available
     */
    public function getImage(): ?Apic
    {
        $images = $this->getImages();
        return count($images) > 0 ? $images[0] : null;
    }

    /**
     * Get the back cover image from the audio file.
     *
     * @return Apic|null The back cover image, or null if not available
     */
    public function getBackCoverImage(): ?Apic
    {
        return $this->getImageByType(self::IMAGE_COVER_BACK);
    }

    /**
     * Get the artist image from the audio file.
     *
     * @return Apic|null The artist image, or null if not available
     */
    public function getArtistImage(): ?Apic
    {
        return $this->getImageByType(self::IMAGE_ARTIST);
    }

    /**
     * Get the front cover image data from the audio file.
     *
     * @return string|null The front cover image data, or null if not available
     */
    public function getFrontCoverImageData(): ?string
    {
        return $this->getImageDataByType(self::IMAGE_COVER_FRONT);
    }

    /**
     * Get the image data from a specific type of APIC frame.
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return string|null The image data, or null if not available
     */
    public function getImageDataByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);

        return $image?->getImageData();
    }

    /**
     * Get the image data from the first APIC frame.
     *
     * @return string|null The image data, or null if not available
     */
    public function getImageData(): ?string
    {
        $image = $this->getImage();

        return $image?->getImageData();
    }

    /**
     * Get the MIME type of the first APIC frame.
     *
     * @return string|null The MIME type, or null if not available
     */
    public function getImageMimeType(): ?string
    {
        $image = $this->getImage();
        return $image?->getMimeType();
    }

    public function getMimeType(): string
    {
        return new \finfo(FILEINFO_MIME_TYPE)->file($this->filePath);
    }

    /**
     * Get the MIME type of a specific type of APIC frame.
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return string|null The MIME type, or null if not available
     */
    public function getImageMimeTypeByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);

        return $image?->getMimeType();
    }

    /**
     * Get the image description of the first APIC frame.
     *
     * @return string|null The image description, or null if not available
     */
    public function getImageDescription(): ?string
    {
        $image = $this->getImage();

        return $image?->getDescription();
    }

    /**
     * Get the image description of a specific type of APIC frame.
     *
     * @param int $imageType The image type (use the IMAGE_* constants)
     * @return string|null The image description, or null if not available
     */
    public function getImageDescriptionByType(int $imageType): ?string
    {
        $image = $this->getImageByType($imageType);

        return $image?->getDescription();
    }

    public function isAudioFile(): bool
    {
        return Str::startsWith($this->getMimeType(), 'audio/');
    }

    public function probeLength()
    {
        $stream = $this->getStreams()->first();

        if (!$stream) {
            return 0;
        }

        return (float)$stream->get('duration');
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
