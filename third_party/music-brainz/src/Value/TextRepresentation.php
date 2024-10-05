<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A text representation
 */
class TextRepresentation implements Value
{
    use Property\LanguageTrait;
    use Property\ScriptTrait;

    /**
     * Constructs a text representation.
     *
     * @param array $textRepresentation Array of information about a text representation
     */
    public function __construct(array $textRepresentation = [])
    {
        $this->setLanguageFromArray($textRepresentation);
        $this->setScriptFromArray($textRepresentation);
    }

    /**
     * Returns the text representation as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        if (empty((string) $this->getScript())) {
            $textRepresentation = (empty((string) $this->getLanguage()))
                ? ''
                : (string) $this->getLanguage();
        } else {
            $textRepresentation = $this->getScript();
            $textRepresentation .= (empty((string) $this->getLanguage()))
                ? ''
                : ' (' . $this->getLanguage() . ')';
        }

        return $textRepresentation;
    }
}
