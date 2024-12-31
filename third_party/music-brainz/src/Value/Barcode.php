<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Barcodes are numbers used as stock control mechanisms by retailers: as such they are highly standardised and well
 * recognised, and form an invaluable identifier for communication between companies. On physical releases, they usually
 * appear in the form of a machine-readable series of black and white bars, hence the name "barcode".
 *
 * @see https://musicbrainz.org/doc/Barcode
 */
class Barcode implements Value
{
    /**
     * The barcode
     *
     * @var string
     */
    private $code;

    /**
     * Constructs a barcode.
     *
     * @param string $code A barcode
     */
    public function __construct(string $code = '')
    {
        $this->code = $code;
    }

    /**
     * Returns the barcode as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
