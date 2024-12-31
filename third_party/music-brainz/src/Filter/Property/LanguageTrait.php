<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Language;

trait LanguageTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the language.
     *
     * @return string
     */
    public static function language(): string
    {
        return 'lang';
    }

    /**
     * Adds the language.
     *
     * @param Language $language The language
     *
     * @return Term
     */
    public function addLanguage(Language $language): Term
    {
        return $this->addTerm((string) $language, self::language());
    }
}
