<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Search;

use AskLucy\Expression\Clause\Clause;
use AskLucy\Expression\Clause\Phrase;
use AskLucy\Expression\Clause\Query;
use AskLucy\Expression\Clause\Term;

/**
 * An abstract filter
 */
abstract class AbstractFilter
{
    /**
     * The lucene search query created by this filter
     *
     * @var Query
     */
    protected Query $query;

    /**
     * Constructs an annotation filter.
     */
    public function __construct()
    {
        $this->query = new Query();
    }

    /**
     * Adds a clause to the filter.
     *
     * @param Clause $clause A clause
     *
     * @return self
     */
    public function add(Clause $clause): self
    {
        $this->query->add($clause);

        return $this;
    }

    /**
     * Returns the filter as Lucene query string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->query;
    }

    /**
     * Creates a new term by given search word and field name, adds it to the filter query and returns it.
     *
     * @param string $searchWord A search word
     * @param string $field      The name of the field to search in
     *
     * @return Term
     */
    protected function addTerm(string $searchWord, string $field): Term
    {
        $term = new Term($searchWord, $field);

        $this->add($term);

        return $term;
    }

    /**
     * Creates a new phrase by given search words and field name, adds it to the filter query and returns it.
     *
     * @param string $searchWords Search words
     * @param string $field       The name of the field to search in
     *
     * @return Phrase
     */
    protected function addPhrase(string $searchWords, string $field): Phrase
    {
        $phrase = new Phrase($searchWords, $field);

        $this->add($phrase);

        return $phrase;
    }
}
