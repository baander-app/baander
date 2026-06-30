<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Util\InBuffer;
use Tsduck\Util\NativeObject;
use Tsduck\Util\OutBuffer;

/**
 * A TSDuck section file for loading, saving, and manipulating MPEG-TS sections.
 *
 * SectionFile can load sections from binary files, XML documents, or raw byte
 * buffers. It can save sections in binary, XML, or JSON format. It also provides
 * methods for querying section/table counts, binary size, CRC validation control,
 * and EIT reorganization.
 *
 * Usage:
 *   $duck = new DuckContext();
 *   $sf = new SectionFile($duck);
 *   $sf->loadBinary('sections.bin');
 *   echo $sf->sectionsCount() . " sections\n";
 *   echo $sf->toXML();
 *   $sf->close();
 *   $duck->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class SectionFile extends NativeObject
{
    // =========================================================================
    // CRC32 validation mode constants (matching C++ counterparts)
    // =========================================================================

    /** Ignore the section CRC32 when loading a binary section. This is the default. */
    public const CRC32_IGNORE = 0;

    /** Check that the value of the CRC32 of the section is correct and fail if it isn't. */
    public const CRC32_CHECK = 1;

    /** Recompute a fresh new CRC32 value based on the content of the section. */
    public const CRC32_COMPUTE = 2;

    /**
     * The DuckContext used by this SectionFile.
     */
    private readonly DuckContext $duck;

    /**
     * Creates a new SectionFile bound to the given DuckContext.
     *
     * @param DuckContext $context The DuckContext to use for character set,
     *                             standards, and other configuration
     */
    public function __construct(DuckContext $context)
    {
        $ffi = LibTSDuck::getInstance();
        $this->duck = $context;

        // void* tspyNewSectionFile(void* duck)
        $pointer = $ffi->tspyNewSectionFile($context->nativePointer());

        parent::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ SectionFile object.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteSectionFile($pointer);
        }
    }

    /**
     * Clears the content of the SectionFile, erasing all sections.
     */
    public function clear(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspySectionFileClear($this->getPointer());
    }

    /**
     * Returns the size in bytes of all sections.
     *
     * This would be the size of the corresponding binary file.
     *
     * @return int The size in bytes of all sections
     */
    public function binarySize(): int
    {
        $this->assertNotClosed();

        return (int) $this->ffi->tspySectionFileBinarySize($this->getPointer());
    }

    /**
     * Returns the total number of sections in the file.
     *
     * @return int The total number of sections
     */
    public function sectionsCount(): int
    {
        $this->assertNotClosed();

        return (int) $this->ffi->tspySectionFileSectionsCount($this->getPointer());
    }

    /**
     * Returns the total number of full tables in the file.
     *
     * Orphan sections (not part of a complete table) are not included.
     *
     * @return int The total number of full tables
     */
    public function tablesCount(): int
    {
        $this->assertNotClosed();

        return (int) $this->ffi->tspySectionFileTablesCount($this->getPointer());
    }

    /**
     * Sets the CRC32 processing mode when loading binary sections.
     *
     * @param int $mode The CRC32 validation mode. Must be one of:
     *                  self::CRC32_IGNORE, self::CRC32_CHECK, self::CRC32_COMPUTE
     */
    public function setCRCValidation(int $mode): void
    {
        $this->assertNotClosed();
        $this->ffi->tspySectionFileSetCRCValidation($this->getPointer(), $mode);
    }

    /**
     * Loads a binary section file from disk.
     *
     * The loaded sections are added to the content of this object
     * (existing sections are preserved).
     *
     * @param string $filename Binary file name. If the file name is empty
     *                         or "-", the standard input is used.
     *
     * @throws TsduckException If the file cannot be loaded
     */
    public function loadBinary(string $filename): void
    {
        $this->assertNotClosed();

        $result = $this->callBoolWithStringArg('tspySectionFileLoadBinary', $filename);

        if (!$result) {
            throw new TsduckException(sprintf(
                'Failed to load binary section file "%s".',
                $filename,
            ));
        }
    }

    /**
     * Saves all sections to a binary file.
     *
     * @param string $filename Binary file name. If the file name is empty
     *                         or "-", the standard output is used.
     *
     * @throws TsduckException If the file cannot be saved
     */
    public function saveBinary(string $filename): void
    {
        $this->assertNotClosed();

        $result = $this->callBoolWithStringArg('tspySectionFileSaveBinary', $filename);

        if (!$result) {
            throw new TsduckException(sprintf(
                'Failed to save binary section file "%s".',
                $filename,
            ));
        }
    }

    /**
     * Loads sections from an XML file.
     *
     * The loaded tables are added to the content of this object
     * (existing sections are preserved).
     *
     * If the file name starts with "<?xml", this is considered as
     * "inline XML content" instead of a file path.
     *
     * @param string $filename XML file name. If the file name is empty
     *                         or "-", the standard input is used.
     *
     * @throws TsduckException If the XML file cannot be loaded
     */
    public function loadXML(string $filename): void
    {
        $this->assertNotClosed();

        $result = $this->callBoolWithStringArg('tspySectionFileLoadXML', $filename);

        if (!$result) {
            throw new TsduckException(sprintf(
                'Failed to load XML section file "%s".',
                $filename,
            ));
        }
    }

    /**
     * Saves all sections to an XML file.
     *
     * @param string $filename XML file name. If the file name is empty
     *                         or "-", the standard output is used.
     *
     * @throws TsduckException If the XML file cannot be saved
     */
    public function saveXML(string $filename): void
    {
        $this->assertNotClosed();

        $result = $this->callBoolWithStringArg('tspySectionFileSaveXML', $filename);

        if (!$result) {
            throw new TsduckException(sprintf(
                'Failed to save XML section file "%s".',
                $filename,
            ));
        }
    }

    /**
     * Saves all sections to a JSON file after automated XML-to-JSON conversion.
     *
     * @param string $filename JSON file name. If the file name is empty
     *                         or "-", the standard output is used.
     *
     * @throws TsduckException If the JSON file cannot be saved
     */
    public function saveJSON(string $filename): void
    {
        $this->assertNotClosed();

        $result = $this->callBoolWithStringArg('tspySectionFileSaveJSON', $filename);

        if (!$result) {
            throw new TsduckException(sprintf(
                'Failed to save JSON section file "%s".',
                $filename,
            ));
        }
    }

    /**
     * Loads sections from a binary memory buffer.
     *
     * The loaded sections are added to the content of this object
     * (existing sections are preserved).
     *
     * @param string $data Binary data containing MPEG-TS sections
     *
     * @throws TsduckException If some sections were incorrect or truncated
     */
    public function fromBinary(string $data): void
    {
        $this->assertNotClosed();

        $size = strlen($data);
        $carray = $this->ffi->new("uint8_t[{$size}]", false, false);

        for ($i = 0; $i < $size; $i++) {
            $carray[$i] = ord($data[$i]);
        }

        // bool tspySectionLoadBuffer(void* sf, const uint8_t* buffer, size_t size)
        $result = $this->ffi->tspySectionLoadBuffer($this->getPointer(), $carray, $size);

        if (!$result) {
            throw new TsduckException(
                'Failed to load sections from binary buffer: '
                . 'some sections were incorrect or truncated.',
            );
        }
    }

    /**
     * Returns the binary content of all sections as a byte string.
     *
     * @return string Binary data containing all MPEG-TS sections
     */
    public function toBinary(): string
    {
        $this->assertNotClosed();

        $size = $this->binarySize();

        if ($size === 0) {
            return '';
        }

        $carray = $this->ffi->new("uint8_t[{$size}]", false, false);
        $sizePtr = $this->ffi->new('size_t', false, false);
        $sizePtr->cdata = $size;

        // void tspySectionSaveBuffer(void* sf, uint8_t* buffer, size_t* size)
        $this->ffi->tspySectionSaveBuffer($this->getPointer(), $carray, FFI::addr($sizePtr));

        $actualSize = (int) $sizePtr->cdata;
        $result = '';

        for ($i = 0; $i < $actualSize; $i++) {
            $result .= chr($carray[$i]);
        }

        return $result;
    }

    /**
     * Serializes all sections as an XML document string.
     *
     * Uses the double-buffer pattern: first attempts with an initial buffer size,
     * then retries with the actual required size if the buffer was too small.
     *
     * @return string Complete XML document text, or empty string on error
     */
    public function toXML(): string
    {
        $this->assertNotClosed();

        // First attempt with a reasonable initial size.
        $buf = new OutBuffer($this->ffi, 2048);

        // size_t tspySectionFileToXML(void* sf, uint8_t* buffer, size_t* size)
        $requiredSize = (int) $this->ffi->tspySectionFileToXML(
            $this->getPointer(),
            $buf->getBuffer(),
            $buf->getSizePtr(),
        );

        if ($requiredSize > 2048) {
            // First try was too short -- retry with the actual required size.
            $buf = new OutBuffer($this->ffi, $requiredSize);
            $this->ffi->tspySectionFileToXML(
                $this->getPointer(),
                $buf->getBuffer(),
                $buf->getSizePtr(),
            );
        }

        return $buf->toString();
    }

    /**
     * Serializes all sections as a JSON document string.
     *
     * Uses the double-buffer pattern: first attempts with an initial buffer size,
     * then retries with the actual required size if the buffer was too small.
     *
     * @return string Complete JSON document text, or empty string on error
     */
    public function toJSON(): string
    {
        $this->assertNotClosed();

        // First attempt with a reasonable initial size.
        $buf = new OutBuffer($this->ffi, 2048);

        // size_t tspySectionFileToJSON(void* sf, uint8_t* buffer, size_t* size)
        $requiredSize = (int) $this->ffi->tspySectionFileToJSON(
            $this->getPointer(),
            $buf->getBuffer(),
            $buf->getSizePtr(),
        );

        if ($requiredSize > 2048) {
            // First try was too short -- retry with the actual required size.
            $buf = new OutBuffer($this->ffi, $requiredSize);
            $this->ffi->tspySectionFileToJSON(
                $this->getPointer(),
                $buf->getBuffer(),
                $buf->getSizePtr(),
            );
        }

        return $buf->toString();
    }

    /**
     * Reorganizes all EIT sections according to ETSI TS 101 211.
     *
     * Only one EIT present/following subtable is kept per service. It is split
     * in two sections if two events (present and following) are specified. All
     * EIT schedule sections are kept but completely reorganized. All events are
     * extracted and spread over new EIT sections according to ETSI TS 101 211.
     *
     * The "last midnight" according to which EIT segments are assigned is derived
     * from the year, month, and day parameters. If any of them is out of range,
     * the start time of the oldest event in the section file is used as the
     * "reference date".
     *
     * @param int $year  Year of the reference time for EIT schedule (0 = auto)
     * @param int $month Month (1..12) of the reference time for EIT schedule (0 = auto)
     * @param int $day   Day (1..31) of the reference time for EIT schedule (0 = auto)
     *
     * @see ETSI TS 101 211, section 4.1.4
     */
    public function reorganizeEITs(int $year = 0, int $month = 0, int $day = 0): void
    {
        $this->assertNotClosed();
        $this->ffi->tspySectionFileReorganizeEITs(
            $this->getPointer(),
            $year,
            $month,
            $day,
        );
    }

    /**
     * Calls a boolean-returning tspy* function with a UTF-16 encoded string argument.
     *
     * @param string $funcName The FFI function name to call
     * @param string $arg      The string argument to encode as UTF-16 LE
     *
     * @return bool The boolean result from the C function
     */
    private function callBoolWithStringArg(string $funcName, string $arg): bool
    {
        $buf = new InBuffer($this->ffi);
        $buf->append($arg);

        return (bool) $this->ffi->{$funcName}(
            $this->getPointer(),
            $buf->getBuffer(),
            $buf->getSize(),
        );
    }
}
