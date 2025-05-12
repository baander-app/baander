<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TLAN frame - Language(s).
 *
 * The 'Language(s)' frame should contain the languages of the text or lyrics spoken or sung in the audio.
 * The language is represented with three characters according to ISO-639-2. If more than one language is used
 * in the text, their language codes should follow according to the amount of their usage.
 */
class TLAN extends TextFrame
{
    /**
     * Constructs the TLAN frame with given parameters.
     */
    public function __construct(string $language = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TLAN', $language, $encoding);
    }

    /**
     * Returns the language(s).
     */
    public function getLanguage(): string
    {
        return $this->getText();
    }

    /**
     * Sets the language(s).
     */
    public function setLanguage(string $language): self
    {
        return $this->setText($language);
    }
}