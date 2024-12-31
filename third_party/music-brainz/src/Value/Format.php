<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value;

/**
 * A releases format
 *
 * @see https://musicbrainz.org/doc/Release/Format
 */
class Format implements Value
{
    use Value\Property\MBIDTrait;

    /**
     * The code of the format
     *
     * @var string
     */
    private $code;

    /**
     * Constructs a format.
     *
     * @param array $format Information about the format from the webservice
     */
    public function __construct(array $format = [])
    {
        $this->code = ArrayAccess::getString($format, 'format') ?? '';
        $this->setMbidFromArray($format, 'format-id');
    }

    /**
     * Returns the code of the format.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
