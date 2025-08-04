<?php

namespace Baander\RedisStack\Index;

use Baander\RedisStack\RediSearch;

class IndexDefinition
{
    private ?array $fields = [];
    private ?string $prefix = null;
    private ?array $stopWords = null;
    private ?string $defaultLanguage = null;

    public function __construct(private readonly string $indexName)
    {
    }

    public function addField(FieldDefinition $field): self
    {
        $this->fields[] = $field;
        return $this;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function disableStopWords(): self
    {
        $this->stopWords = [];
        return $this;
    }

    public function setDefaultLanguage(string $language)
    {
        if (RediSearch::isLanguageSupported($language)) {
            $this->defaultLanguage = $language;
        }
    }

    public function generateCommand(): array
    {
        $command = [$this->indexName, 'ON', 'HASH'];

        if ($this->defaultLanguage) {
            $command[] = "LANGUAGE {$this->defaultLanguage}";
        }

        if ($this->prefix) {
            $command[] = 'PREFIX';
            $command[] = 1;
            $command[] = $this->prefix;
        }

        if ($this->stopWords !== null) {
            $command[] = 'STOPWORDS';
            $command[] = count($this->stopWords);
            $command = array_merge($command, $this->stopWords);
        }

        $command[] = 'SCHEMA';
        foreach ($this->fields as $field) {
            $command = array_merge($command, $field->getSchema());
        }

        return $command;
    }
}