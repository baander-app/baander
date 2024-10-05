<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\LocaleCode;

use function is_null;

/**
 * Provides a getter for the locale code.
 */
trait LocaleCodeTrait
{
    /**
     * The locale code
     *
     * @var LocaleCode
     */
    private LocaleCode $localeCode;

    /**
     * Returns the locale code.
     *
     * @return LocaleCode
     */
    public function getLocaleCode(): LocaleCode
    {
        return $this->localeCode;
    }

    /**
     * Sets the locale code by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLocaleCodeFromArray(array $input): void
    {
        $this->localeCode = is_null($localeCode = ArrayAccess::getString($input, 'locale'))
            ? new LocaleCode()
            : new LocaleCode($localeCode);
    }
}
