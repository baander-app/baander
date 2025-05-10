<?php

namespace Baander\RedisStack\Search\Query;

class QueryOptions
{
    private array $options = [];

    /**
     * Include scores in the query results.
     */
    public function withScores(): self
    {
        $this->options[] = 'WITHSCORES';
        return $this;
    }

    /**
     * Exclude content from the query results (return only metadata).
     */
    public function noContent(): self
    {
        $this->options[] = 'NOCONTENT';
        return $this;
    }

    /**
     * Return specific fields from the query results.
     *
     * @param array $fields The list of fields to return.
     */
    public function returnFields(array $fields): self
    {
        $this->options[] = 'RETURN';
        $this->options[] = count($fields); // Number of fields to return
        foreach ($fields as $field) {
            $this->options[] = $field; // Add each field as a separate argument
        }
        return $this;
    }

    /**
     * Add summarization to the query results.
     *
     * @param array $fields The fields to summarize.
     * @param int $fragmentCount Number of fragments to return.
     * @param int $fragmentLength Maximum length of each fragment.
     * @param string $separator Separator used between fragments.
     */
    public function summarize(array $fields, int $fragmentCount = 3, int $fragmentLength = 50, string $separator = '...'): self
    {
        $this->options[] = 'SUMMARIZE';
        if (!empty($fields)) {
            $this->options[] = 'FIELDS';
            $this->options[] = count($fields);
            foreach ($fields as $field) {
                $this->options[] = $field;
            }
        }
        $this->options[] = 'FRAGS';
        $this->options[] = $fragmentCount;
        $this->options[] = 'LEN';
        $this->options[] = $fragmentLength;
        $this->options[] = 'SEPARATOR';
        $this->options[] = $separator;

        return $this;
    }

    /**
     * Add highlighting to the query results.
     *
     * @param array $fields The fields to highlight.
     * @param string $openTag The opening HTML tag for highlights.
     * @param string $closeTag The closing HTML tag for highlights.
     */
    public function highlight(array $fields, string $openTag = '<strong>', string $closeTag = '</strong>'): self
    {
        $this->options[] = 'HIGHLIGHT';
        $this->options[] = 'FIELDS';
        $this->options[] = count($fields); // Number of fields
        foreach ($fields as $field) {
            $this->options[] = $field;
        }
        $this->options[] = 'TAGS';
        $this->options[] = $openTag;
        $this->options[] = $closeTag;

        return $this;
    }


    public function setLanguage(string $language): self
    {
        $this->options[] = sprintf('LANGUAGE %s', $language);
        return $this;
    }

    public function setScorer(string $scorer): self
    {
        $this->options[] = sprintf('SCORER %s', $scorer);
        return $this;
    }

    public function setExpander(string $expander): self
    {
        $this->options[] = sprintf('EXPANDER %s', $expander);
        return $this;
    }

    public function setPayload(string $payload): self
    {
        $this->options[] = sprintf('PAYLOAD %s', $payload);
        return $this;
    }

    public function inKeys(array $keys): self
    {
        $count = count($keys);
        $keyList = implode(' ', $keys);
        $this->options[] = sprintf('INKEYS %d %s', $count, $keyList);
        return $this;
    }

    public function inFields(array $fields): self
    {
        $count = count($fields);
        $fieldList = implode(' ', $fields);
        $this->options[] = sprintf('INFIELDS %d %s', $count, $fieldList);
        return $this;
    }

    public function setSlop(int $slop): self
    {
        $this->options[] = sprintf('SLOP %d', $slop);
        return $this;
    }

    public function noStopWords(): self
    {
        $this->options[] = 'NOSTOPWORDS';
        return $this;
    }

    public function verbatim(): self
    {
        $this->options[] = 'VERBATIM';
        return $this;
    }

    /**
     * Retrieve all options as an array.
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}