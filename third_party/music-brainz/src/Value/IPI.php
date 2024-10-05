<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function strlen;

/**
 * An IPI (interested party information) code is an identifying number assigned by the CISAC database for musical rights
 * management.
 *
 * @see https://musicbrainz.org/doc/IPI
 */
class IPI implements Value
{
    /**
     * The IPI code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs an IPI code.
     *
     * @param string $code An IPI code
     */
    public function __construct(string $code = '')
    {
        $this->code = (11 === strlen($code) && ctype_digit($code))
            ? $code
            : '';
    }

    /**
     * Returns the IPI code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
