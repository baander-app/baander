<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of languages
 */
class LanguageList extends ValueList
{
    /**
     * Constructs a list of languages.
     *
     * @param array $languages An array containing information about the languages
     */
    public function __construct(array $languages = [])
    {
        parent::__construct(
            array_map(
                function ($language) {
                    return new Language($language);
                },
                $languages
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Language::class;
    }
}
