<?php

namespace App\Modules\Metadata\MediaMeta;

use App\Modules\Metadata\MediaMeta\Frame\Apic;
use App\Modules\Metadata\MediaMeta\Frame\COMM;
use App\Modules\Metadata\MediaMeta\Frame\EQUA;
use App\Modules\Metadata\MediaMeta\Frame\ETCO;
use App\Modules\Metadata\MediaMeta\Frame\Frame;
use App\Modules\Metadata\MediaMeta\Frame\IPLS;
use App\Modules\Metadata\MediaMeta\Frame\LINK;
use App\Modules\Metadata\MediaMeta\Frame\MCDI;
use App\Modules\Metadata\MediaMeta\Frame\MVIN;
use App\Modules\Metadata\MediaMeta\Frame\OWNE;
use App\Modules\Metadata\MediaMeta\Frame\POPM;
use App\Modules\Metadata\MediaMeta\Frame\SYLT;
use App\Modules\Metadata\MediaMeta\Frame\TALB;
use App\Modules\Metadata\MediaMeta\Frame\TCOM;
use App\Modules\Metadata\MediaMeta\Frame\TCON;
use App\Modules\Metadata\MediaMeta\Frame\TDAT;
use App\Modules\Metadata\MediaMeta\Frame\TEXT;
use App\Modules\Metadata\MediaMeta\Frame\TextFrame;
use App\Modules\Metadata\MediaMeta\Frame\TIT2;
use App\Modules\Metadata\MediaMeta\Frame\TLAN;
use App\Modules\Metadata\MediaMeta\Frame\TLEN;
use App\Modules\Metadata\MediaMeta\Frame\TORY;
use App\Modules\Metadata\MediaMeta\Frame\TPE1;
use App\Modules\Metadata\MediaMeta\Frame\TPE2;
use App\Modules\Metadata\MediaMeta\Frame\TPE3;
use App\Modules\Metadata\MediaMeta\Frame\TPE4;
use App\Modules\Metadata\MediaMeta\Frame\TPUB;
use App\Modules\Metadata\MediaMeta\Frame\TRCK;
use App\Modules\Metadata\MediaMeta\Frame\TSRC;
use App\Modules\Metadata\MediaMeta\Frame\TYER;
use App\Modules\Metadata\MediaMeta\Frame\USLT;
use Exception;

/**
 * This class represents a file containing ID3v2 tags.
 *
 * ID3v2 is a flexible tagging format that stores metadata at the beginning of the audio file.
 * It supports various types of frames for different kinds of metadata, including images.
 */
class Id3v2
{
    /** @var string */
    private $filePath;

    /** @var int */
    private $version = 0;

    /** @var array */
    private $frames = [];

    /** @var int */
    private $encoding = Encoding::UTF8;

    /**
     * Constructs the Id3v2 class with given file.
     *
     * @param string $filePath The path to the audio file
     * @param array $options Options for reading the tag
     * @throws Exception if the file cannot be read or does not contain ID3v2 tags
     */
    public function __construct(string $filePath, array $options = [])
    {
        $this->filePath = $filePath;

        if (isset($options['encoding'])) {
            $this->encoding = $options['encoding'];
        }

        $this->parse();
    }

    /**
     * Parses the ID3v2 tag from the file.
     *
     * @throws Exception if the file cannot be read or does not contain ID3v2 tags
     */
    private function parse(): void
    {
        if (!file_exists($this->filePath)) {
            throw new Exception("File not found: {$this->filePath}");
        }

        $handle = fopen($this->filePath, 'rb');
        if (!$handle) {
            throw new Exception("Cannot open file: {$this->filePath}");
        }

        // Read the ID3v2 header (10 bytes)
        $header = fread($handle, 10);

        // Check if the file has an ID3v2 tag
        if (!str_starts_with($header, 'ID3')) {
            fclose($handle);
            throw new Exception("File does not contain ID3v2 tag");
        }

        // Parse the header
        $this->version = ord($header[3]);

        // Check if the version is supported
        if ($this->version < 3 || $this->version > 4) {
            fclose($handle);
            throw new Exception("Unsupported ID3v2 version: {$this->version}");
        }

        // Get the tag size (bytes 6-9, 7-bit format)
        $size = (
            (ord($header[6]) & 0x7F) << 21 |
            (ord($header[7]) & 0x7F) << 14 |
            (ord($header[8]) & 0x7F) << 7 |
            (ord($header[9]) & 0x7F)
        );

        // Read the entire tag
        fseek($handle, 10); // Skip the header
        $tagData = fread($handle, $size);
        fclose($handle);

        // Parse the frames
        $this->parseFrames($tagData);
    }

    /**
     * Parses the frames from the tag data.
     *
     * @param string $tagData The tag data
     */
    private function parseFrames(string $tagData): void
    {
        $offset = 0;
        $tagSize = strlen($tagData);

        while ($offset < $tagSize) {
            // Check if we've reached padding (0x00 bytes)
            if (ord($tagData[$offset]) === 0) {
                break;
            }

            // Frame ID is 4 bytes
            $frameId = substr($tagData, $offset, 4);
            $offset += 4;

            // Frame size is 4 bytes
            $frameSize = (
                ord($tagData[$offset]) << 24 |
                ord($tagData[$offset + 1]) << 16 |
                ord($tagData[$offset + 2]) << 8 |
                ord($tagData[$offset + 3])
            );
            $offset += 4;

            // Frame flags are 2 bytes (skip for now)
            $offset += 2;

            // Read the frame data
            $frameData = substr($tagData, $offset, $frameSize);
            $offset += $frameSize;

            // Process the frame based on its ID
            $this->processFrame($frameId, $frameData);
        }
    }

    /**
     * Processes a frame based on its ID.
     *
     * @param string $frameId The frame ID
     * @param string $frameData The frame data
     */
    private function processFrame(string $frameId, string $frameData): void
    {
        // Store the frame data by ID
        if (!isset($this->frames[$frameId])) {
            $this->frames[$frameId] = [];
        }

        // Create the appropriate frame object based on the frame ID
        $frame = $this->createFrame($frameId, $frameData);

        if ($frame !== null) {
            $this->frames[$frameId][] = $frame;
        } else {
            // For unknown frames, store the raw data
            $this->frames[$frameId][] = $frameData;
        }
    }

    /**
     * Creates a frame object based on the frame ID.
     */
    private function createFrame(string $frameId, string $frameData): ?Frame
    {
        $frame = match ($frameId) {
            'TIT2' => new TIT2(encoding: $this->encoding),
            'TPE1' => new TPE1(encoding: $this->encoding),
            'TPE2' => new TPE2(encoding: $this->encoding),
            'TPE3' => new TPE3(encoding: $this->encoding),
            'TPE4' => new TPE4(encoding: $this->encoding),
            'TALB' => new TALB(encoding: $this->encoding),
            'TCON' => new TCON(encoding: $this->encoding),
            'TYER' => new TYER(encoding: $this->encoding),
            'TCOM' => new TCOM(encoding: $this->encoding),
            'TDAT' => new TDAT(encoding: $this->encoding),
            'TEXT' => new TEXT(encoding: $this->encoding),
            'TLAN' => new TLAN(encoding: $this->encoding),
            'TLEN' => new TLEN(encoding: $this->encoding),
            'TORY' => new TORY(encoding: $this->encoding),
            'TPUB' => new TPUB(encoding: $this->encoding),
            'TSRC' => new TSRC(encoding: $this->encoding),
            'TRCK' => new TRCK(encoding: $this->encoding),
            'MVIN' => new MVIN(encoding: $this->encoding),
            'APIC' => new Apic(encoding: $this->encoding),
            'COMM' => new COMM(encoding: $this->encoding),
            'USLT' => new USLT(encoding: $this->encoding),
            'SYLT' => new SYLT(encoding: $this->encoding),
            'POPM' => new POPM(),
            'OWNE' => new OWNE(encoding: $this->encoding),
            'IPLS' => new IPLS(encoding: $this->encoding),
            'LINK' => new LINK(),
            'MCDI' => new MCDI(),
            'ETCO' => new ETCO(),
            'EQUA' => new EQUA(),
            default => str_starts_with($frameId, 'T')
                ? new TextFrame($frameId, encoding: $this->encoding)
                : null
        };

        // Parse the frame data if a frame was created
        return $frame?->parse($frameData);
    }

    /**
     * Returns the APIC frame (APIC).
     *
     *
     * @return Apic|null The APIC frame, or null if not available
     */
    public function getApicFrame(): ?Apic
    {
        $frames = $this->getFramesByIdentifier('APIC');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns all frames with the given identifier.
     *
     * @param string $identifier The frame identifier
     * @return array The frames
     */
    public function getFramesByIdentifier(string $identifier): array
    {
        return $this->frames[$identifier] ?? [];
    }

    /**
     * Sets the APIC frame (APIC).
     *
     * @param string $imagePath The path to the image file
     * @param string $imageType The image type (e.g. "image/jpeg")
     * @return self
     */
    public function setApicFrame(string $imagePath, string $imageType): self
    {
        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->file($imagePath);
        if ($mimeType === false) {
            return $this;
        }

        $frame = new Apic(
            data: [
                'mimeType'  => $mimeType,
                'imageType' => $imageType,
                'imageData' => file_get_contents($imagePath),
            ],
            encoding: $this->encoding,
        );
        $this->frames['APIC'] = [$frame];

        return $this;
    }

    /**
     * Returns the title frame (TIT2).
     *
     * @return TIT2|null The title frame, or null if not available
     */
    public function getTIT2Frame(): ?TIT2
    {
        $frames = $this->getFramesByIdentifier('TIT2');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the title frame (TIT2).
     *
     * @param string $title The title
     * @return self
     */
    public function setTIT2Frame(string $title): self
    {
        $frame = new TIT2($title, $this->encoding);
        $this->frames['TIT2'] = [$frame];
        return $this;
    }

    /**
     * Returns the artist frame (TPE1).
     *
     * @return TPE1|null The artist frame, or null if not available
     */
    public function getTPE1Frame(): ?TPE1
    {
        $frames = $this->getFramesByIdentifier('TPE1');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the artist frame (TPE1).
     *
     * @param string $artist The artist
     * @return self
     */
    public function setTPE1Frame(string $artist): self
    {
        $frame = new TPE1($artist, $this->encoding);
        $this->frames['TPE1'] = [$frame];
        return $this;
    }

    /**
     * Returns the album frame (TALB).
     *
     * @return TALB|null The album frame, or null if not available
     */
    public function getTALBFrame(): ?TALB
    {
        $frames = $this->getFramesByIdentifier('TALB');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the album frame (TALB).
     *
     * @param string $album The album
     * @return self
     */
    public function setTALBFrame(string $album): self
    {
        $frame = new TALB($album, $this->encoding);
        $this->frames['TALB'] = [$frame];
        return $this;
    }

    /**
     * Returns the genre frame (TCON).
     *
     * @return TCON|null The genre frame, or null if not available
     */
    public function getTCONFrame(): ?TCON
    {
        $frames = $this->getFramesByIdentifier('TCON');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the genre frame (TCON).
     *
     * @param string $genre The genre
     * @return self
     */
    public function setTCONFrame(string $genre): self
    {
        $frame = new TCON($genre, $this->encoding);
        $this->frames['TCON'] = [$frame];
        return $this;
    }

    /**
     * Returns the year frame (TYER).
     *
     * @return TYER|null The year frame, or null if not available
     */
    public function getTYERFrame(): ?TYER
    {
        $frames = $this->getFramesByIdentifier('TYER');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the year frame (TYER).
     *
     * @param string $year The year
     * @return self
     */
    public function setTYERFrame(string $year): self
    {
        $frame = new TYER($year, $this->encoding);
        $this->frames['TYER'] = [$frame];
        return $this;
    }

    /**
     * Returns the first comment frame (COMM).
     *
     * @return COMM|null The comment frame, or null if not available
     */
    public function getCOMMFrame(): ?COMM
    {
        $frames = $this->getCOMMFrames();
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the comment frames (COMM).
     *
     * @return array<COMM> The comment frames
     */
    public function getCOMMFrames(): array
    {
        return $this->getFramesByIdentifier('COMM');
    }

    /**
     * Returns a comment frame with the given description.
     *
     * @param string $description The description
     * @return COMM|null The comment frame, or null if not available
     */
    public function getCOMMFrameByDescription(string $description): ?COMM
    {
        $frames = $this->getCOMMFrames();

        return array_find($frames, fn($frame) => $frame->getDescription() === $description);
    }

    /**
     * Sets a comment frame (COMM).
     *
     * @param string $text The comment text
     * @param string $description The description
     * @param string $language The language code
     * @return self
     */
    public function setCOMMFrame(string $text, string $description = '', string $language = 'eng'): self
    {
        $frame = new COMM($text, $description, $language, $this->encoding);

        // Check if a frame with the same description already exists
        $frames = $this->getCOMMFrames();
        $existingFrames = [];
        $found = false;

        foreach ($frames as $existingFrame) {
            if ($existingFrame->getDescription() === $description) {
                $existingFrames[] = $frame;
                $found = true;
            } else {
                $existingFrames[] = $existingFrame;
            }
        }

        if (!$found) {
            $existingFrames[] = $frame;
        }

        $this->frames['COMM'] = $existingFrames;
        return $this;
    }

    /**
     * Removes all comment frames (COMM).
     *
     * @return self
     */
    public function removeCOMMFrames(): self
    {
        $this->frames['COMM'] = [];
        return $this;
    }

    /**
     * Removes a comment frame with the given description.
     *
     * @param string $description The description
     * @return self
     */
    public function removeCOMMFrameByDescription(string $description): self
    {
        $frames = $this->getCOMMFrames();
        $filteredFrames = array_filter($frames, fn($frame) => $frame->getDescription() !== $description);
        $this->frames['COMM'] = array_values($filteredFrames);
        return $this;
    }

    /**
     * Returns the composer frame (TCOM).
     *
     * @return TCOM|null The composer frame, or null if not available
     */
    public function getTCOMFrame(): ?TCOM
    {
        $frames = $this->getFramesByIdentifier('TCOM');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the composer frame (TCOM).
     *
     * @param string $composer The composer
     * @return self
     */
    public function setTCOMFrame(string $composer): self
    {
        $frame = new TCOM($composer, $this->encoding);
        $this->frames['TCOM'] = [$frame];
        return $this;
    }

    /**
     * Returns the date frame (TDAT).
     *
     * @return TDAT|null The date frame, or null if not available
     */
    public function getTDATFrame(): ?TDAT
    {
        $frames = $this->getFramesByIdentifier('TDAT');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the date frame (TDAT).
     *
     * @param string $date The date (DDMM format)
     * @return self
     */
    public function setTDATFrame(string $date): self
    {
        $frame = new TDAT($date, $this->encoding);
        $this->frames['TDAT'] = [$frame];
        return $this;
    }

    /**
     * Returns the lyricist/text writer frame (TEXT).
     *
     * @return TEXT|null The lyricist/text writer frame, or null if not available
     */
    public function getTEXTFrame(): ?TEXT
    {
        $frames = $this->getFramesByIdentifier('TEXT');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the lyricist/text writer frame (TEXT).
     *
     * @param string $lyricist The lyricist/text writer
     * @return self
     */
    public function setTEXTFrame(string $lyricist): self
    {
        $frame = new TEXT($lyricist, $this->encoding);
        $this->frames['TEXT'] = [$frame];
        return $this;
    }

    /**
     * Returns the language frame (TLAN).
     *
     * @return TLAN|null The language frame, or null if not available
     */
    public function getTLANFrame(): ?TLAN
    {
        $frames = $this->getFramesByIdentifier('TLAN');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the language frame (TLAN).
     *
     * @param string $language The language code(s)
     * @return self
     */
    public function setTLANFrame(string $language): self
    {
        $frame = new TLAN($language, $this->encoding);
        $this->frames['TLAN'] = [$frame];
        return $this;
    }

    /**
     * Returns the length frame (TLEN).
     *
     * @return TLEN|null The length frame, or null if not available
     */
    public function getTLENFrame(): ?TLEN
    {
        $frames = $this->getFramesByIdentifier('TLEN');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the length frame (TLEN).
     *
     * @param string $length The length in milliseconds
     * @return self
     */
    public function setTLENFrame(string $length): self
    {
        $frame = new TLEN($length, $this->encoding);
        $this->frames['TLEN'] = [$frame];
        return $this;
    }

    /**
     * Returns the original release year frame (TORY).
     *
     * @return TORY|null The original release year frame, or null if not available
     */
    public function getTORYFrame(): ?TORY
    {
        $frames = $this->getFramesByIdentifier('TORY');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the original release year frame (TORY).
     *
     * @param string $year The original release year
     * @return self
     */
    public function setTORYFrame(string $year): self
    {
        $frame = new TORY($year, $this->encoding);
        $this->frames['TORY'] = [$frame];
        return $this;
    }

    /**
     * Returns the band/orchestra/accompaniment frame (TPE2).
     *
     * @return TPE2|null The band/orchestra/accompaniment frame, or null if not available
     */
    public function getTPE2Frame(): ?TPE2
    {
        $frames = $this->getFramesByIdentifier('TPE2');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the band/orchestra/accompaniment frame (TPE2).
     *
     * @param string $band The band/orchestra/accompaniment
     * @return self
     */
    public function setTPE2Frame(string $band): self
    {
        $frame = new TPE2($band, $this->encoding);
        $this->frames['TPE2'] = [$frame];
        return $this;
    }

    /**
     * Returns the conductor/performer refinement frame (TPE3).
     *
     * @return TPE3|null The conductor/performer refinement frame, or null if not available
     */
    public function getTPE3Frame(): ?TPE3
    {
        $frames = $this->getFramesByIdentifier('TPE3');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the conductor/performer refinement frame (TPE3).
     *
     * @param string $conductor The conductor/performer refinement
     * @return self
     */
    public function setTPE3Frame(string $conductor): self
    {
        $frame = new TPE3($conductor, $this->encoding);
        $this->frames['TPE3'] = [$frame];
        return $this;
    }

    /**
     * Returns the interpreted, remixed, or otherwise modified by frame (TPE4).
     *
     * @return TPE4|null The interpreted, remixed, or otherwise modified by frame, or null if not available
     */
    public function getTPE4Frame(): ?TPE4
    {
        $frames = $this->getFramesByIdentifier('TPE4');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the interpreted, remixed, or otherwise modified by frame (TPE4).
     *
     * @param string $modifier The interpreter/remixer/modifier
     * @return self
     */
    public function setTPE4Frame(string $modifier): self
    {
        $frame = new TPE4($modifier, $this->encoding);
        $this->frames['TPE4'] = [$frame];
        return $this;
    }

    /**
     * Returns the publisher frame (TPUB).
     *
     * @return TPUB|null The publisher frame, or null if not available
     */
    public function getTPUBFrame(): ?TPUB
    {
        $frames = $this->getFramesByIdentifier('TPUB');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the publisher frame (TPUB).
     *
     * @param string $publisher The publisher
     * @return self
     */
    public function setTPUBFrame(string $publisher): self
    {
        $frame = new TPUB($publisher, $this->encoding);
        $this->frames['TPUB'] = [$frame];
        return $this;
    }

    /**
     * Returns the ISRC frame (TSRC).
     *
     * @return TSRC|null The ISRC frame, or null if not available
     */
    public function getTSRCFrame(): ?TSRC
    {
        $frames = $this->getFramesByIdentifier('TSRC');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the ISRC frame (TSRC).
     *
     * @param string $isrc The ISRC
     * @return self
     */
    public function setTSRCFrame(string $isrc): self
    {
        $frame = new TSRC($isrc, $this->encoding);
        $this->frames['TSRC'] = [$frame];
        return $this;
    }

    /**
     * Returns the track number frame (TRCK).
     *
     * @return TRCK|null The track number frame, or null if not available
     */
    public function getTRCKFrame(): ?TRCK
    {
        $frames = $this->getFramesByIdentifier('TRCK');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Sets the track number frame (TRCK).
     *
     * @param string $track The track number
     * @return self
     */
    public function setTRCKFrame(string $track): self
    {
        $frame = new TRCK($track, $this->encoding);
        $this->frames['TRCK'] = [$frame];
        return $this;
    }

    /**
     * Returns the movement number frame (MVIN).
     *
     * @return MVIN|null The movement number frame, or null if not available
     */
    public function getMVINFrame(): ?MVIN
    {
        $frames = $this->getFramesByIdentifier('MVIN');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the first unsynchronized lyric/text transcription frame (USLT).
     *
     * @return USLT|null The unsynchronized lyric/text transcription frame, or null if not available
     */
    public function getUSLTFrame(): ?USLT
    {
        $frames = $this->getUSLTFrames();
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the unsynchronized lyric/text transcription frames (USLT).
     *
     * @return array<USLT> The unsynchronized lyric/text transcription frames
     */
    public function getUSLTFrames(): array
    {
        return $this->getFramesByIdentifier('USLT');
    }

    /**
     * Returns an unsynchronized lyric/text transcription frame with the given description.
     *
     * @param string $description The description
     * @return USLT|null The unsynchronized lyric/text transcription frame, or null if not available
     */
    public function getUSLTFrameByDescription(string $description): ?USLT
    {
        $frames = $this->getUSLTFrames();

        return array_find($frames, fn($frame) => $frame->getDescription() === $description);
    }

    /**
     * Returns the first synchronized lyric/text frame (SYLT).
     *
     * @return SYLT|null The synchronized lyric/text frame, or null if not available
     */
    public function getSYLTFrame(): ?SYLT
    {
        $frames = $this->getSYLTFrames();
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the synchronized lyric/text frames (SYLT).
     *
     * @return array<SYLT> The synchronized lyric/text frames
     */
    public function getSYLTFrames(): array
    {
        return $this->getFramesByIdentifier('SYLT');
    }

    /**
     * Returns a synchronized lyric/text frame with the given description.
     *
     * @param string $description The description
     * @return SYLT|null The synchronized lyric/text frame, or null if not available
     */
    public function getSYLTFrameByDescription(string $description): ?SYLT
    {
        $frames = $this->getSYLTFrames();

        return array_find($frames, fn($frame) => $frame->getDescription() === $description);
    }

    /**
     * Returns the first popularimeter frame (POPM).
     *
     * @return POPM|null The popularimeter frame, or null if not available
     */
    public function getPOPMFrame(): ?POPM
    {
        $frames = $this->getPOPMFrames();
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the popularimeter frames (POPM).
     *
     * @return array<POPM> The popularimeter frames
     */
    public function getPOPMFrames(): array
    {
        return $this->getFramesByIdentifier('POPM');
    }

    /**
     * Returns a popularimeter frame with the given email.
     *
     * @param string $email The email
     * @return POPM|null The popularimeter frame, or null if not available
     */
    public function getPOPMFrameByEmail(string $email): ?POPM
    {
        $frames = $this->getPOPMFrames();

        return array_find($frames, fn($frame) => $frame->getEmail() === $email);
    }

    /**
     * Returns the ownership frame (OWNE).
     *
     * @return OWNE|null The ownership frame, or null if not available
     */
    public function getOWNEFrame(): ?OWNE
    {
        $frames = $this->getFramesByIdentifier('OWNE');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the involved people list frame (IPLS).
     *
     * @return IPLS|null The involved people list frame, or null if not available
     */
    public function getIPLSFrame(): ?IPLS
    {
        $frames = $this->getFramesByIdentifier('IPLS');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the first linked information frame (LINK).
     *
     * @return LINK|null The linked information frame, or null if not available
     */
    public function getLINKFrame(): ?LINK
    {
        $frames = $this->getLINKFrames();
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the linked information frames (LINK).
     *
     * @return array<LINK> The linked information frames
     */
    public function getLINKFrames(): array
    {
        return $this->getFramesByIdentifier('LINK');
    }

    /**
     * Returns the music CD identifier frame (MCDI).
     *
     * @return MCDI|null The music CD identifier frame, or null if not available
     */
    public function getMCDIFrame(): ?MCDI
    {
        $frames = $this->getFramesByIdentifier('MCDI');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the event timing codes frame (ETCO).
     *
     * @return ETCO|null The event timing codes frame, or null if not available
     */
    public function getETCOFrame(): ?ETCO
    {
        $frames = $this->getFramesByIdentifier('ETCO');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the equalization frame (EQUA).
     *
     * @return EQUA|null The equalization frame, or null if not available
     */
    public function getEQUAFrame(): ?EQUA
    {
        $frames = $this->getFramesByIdentifier('EQUA');
        if (count($frames) === 0) {
            return null;
        }

        return $frames[0];
    }

    /**
     * Returns the version of the ID3v2 tag.
     *
     * @return integer
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Writes the ID3v2 tag to the file.
     *
     * @throws Exception if the file cannot be written
     */
    public function write(): void
    {
        // Open the file for reading and writing
        $handle = fopen($this->filePath, 'r+b');
        if (!$handle) {
            throw new Exception("Cannot open file for writing: {$this->filePath}");
        }

        // Read the entire file content
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        fseek($handle, 0, SEEK_SET);
        $fileContent = fread($handle, $fileSize);

        // Check if the file already has an ID3v2 tag
        $hasTag = str_starts_with($fileContent, 'ID3');
        $existingTagSize = 0;

        if ($hasTag) {
            // Calculate the existing tag size
            $existingTagSize = 10 + (
                    (ord($fileContent[6]) & 0x7F) << 21 |
                    (ord($fileContent[7]) & 0x7F) << 14 |
                    (ord($fileContent[8]) & 0x7F) << 7 |
                    (ord($fileContent[9]) & 0x7F)
                );

            // Remove the existing tag
            $fileContent = substr($fileContent, $existingTagSize);
        }

        // Serialize all frames
        $framesData = '';
        foreach ($this->frames as $frameId => $frames) {
            foreach ($frames as $frame) {
                if ($frame instanceof Frame) {
                    // Get the frame data
                    $frameData = $frame->toBytes();

                    // Calculate the frame size
                    $frameSize = strlen($frameData);

                    // Create the frame header (10 bytes)
                    $frameHeader = $frameId;
                    $frameHeader .= chr(($frameSize >> 24) & 0xFF);
                    $frameHeader .= chr(($frameSize >> 16) & 0xFF);
                    $frameHeader .= chr(($frameSize >> 8) & 0xFF);
                    $frameHeader .= chr($frameSize & 0xFF);
                    $frameHeader .= "\0\0"; // Flags

                    // Add the frame to the tag
                    $framesData .= $frameHeader . $frameData;
                }
            }
        }

        // Calculate the tag size (excluding the header)
        $tagSize = strlen($framesData);

        // Create the tag header (10 bytes)
        $tagHeader = 'ID3';
        $tagHeader .= chr($this->version); // Version
        $tagHeader .= "\0"; // Revision
        $tagHeader .= "\0"; // Flags

        // Size is stored as 7-bit bytes (the high bit is always cleared)
        $tagHeader .= chr(($tagSize >> 21) & 0x7F);
        $tagHeader .= chr(($tagSize >> 14) & 0x7F);
        $tagHeader .= chr(($tagSize >> 7) & 0x7F);
        $tagHeader .= chr($tagSize & 0x7F);

        // Write the tag and file content
        fseek($handle, 0, SEEK_SET);
        fwrite($handle, $tagHeader . $framesData . $fileContent);
        fclose($handle);
    }
}
