<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use AskLucy\Expression\Clause\Term;

trait AbstractAdderTrait
{
    /**
     * Creates a new term by given search word and field name, adds it to the filter query and returns it.
     *
     * @param string $searchWord A search word
     * @param string $field      The name of the field to search in
     *
     * @return Term
     */
    abstract protected function addTerm(string $searchWord, string $field): Term;

    /**
     * Creates a new phrase by given search words and field name, adds it to the filter query and returns it.
     *
     * @param string $searchWords Search words
     * @param string $field       The name of the field to search in
     *
     * @return Phrase
     */
    abstract protected function addPhrase(string $searchWords, string $field): Phrase;
}
