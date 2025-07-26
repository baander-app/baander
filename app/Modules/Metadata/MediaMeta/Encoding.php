<?php

namespace App\Modules\Metadata\MediaMeta;

/**
 * The Encoding interface defines constants for text encodings used in ID3v2 frames.
 */
interface Encoding
{
    /** The ISO-8859-1 encoding. */
    public const int ISO88591 = 0;

    /** The UTF-16 Unicode encoding with BOM. */
    public const int UTF16 = 1;

    /** The UTF-16BE Unicode encoding without BOM. */
    public const int UTF16BE = 2;

    /** The UTF-8 Unicode encoding. */
    public const int UTF8 = 3;

    /** The UTF-16LE Unicode encoding without BOM. */
    public const int UTF16LE = 4;

    /**
     * Returns the text encoding.
     */
    public function getEncoding(): int;

    /**
     * Sets the text encoding.
     */
    public function setEncoding(int $encoding): self;
}
