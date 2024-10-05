<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Language;
use MusicBrainz\Value\LanguageList;

use function is_null;

/**
 * Provides a getter for a list of languages.
 */
trait LanguagesTrait
{
    /**
     * A list of languages
     *
     * @var Language[]|LanguageList
     */
    private LanguageList $languages;

    /**
     * Returns a list of languages.
     *
     * @return Language[]|LanguageList
     */
    public function getLanguages(): LanguageList
    {
        return $this->languages;
    }

    /**
     * Sets a list of languages by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLanguagesFromArray(array $input): void
    {
        $this->languages = is_null($languages = ArrayAccess::getArray($input, 'languages'))
            ? new LanguageList()
            : new LanguageList($languages);
    }
}
