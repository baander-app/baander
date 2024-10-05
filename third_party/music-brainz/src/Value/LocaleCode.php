<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * Locale code
 *
 * @link https://wiki.musicbrainz.org/Internationalization
 */
class LocaleCode implements Value
{
    /**
     * The locale code
     *
     * @var string
     */
    private $code;

    /**
     * Constructs a locale code.
     *
     * @param string $localeCode A locale code
     */
    public function __construct(string $localeCode = '')
    {
        $this->code = $localeCode;
    }

    /**
     * Returns the locale code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
