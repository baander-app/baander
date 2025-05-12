<?php

namespace Baander\RedisStack\Fields;

class TagField extends Field
{
    private array $values;
    private array $charactersToEscape;

    public function __construct(string $fieldName, array $values, array $charactersToEscape = [' ', '-'])
    {
        parent::__construct($fieldName);
        $this->values = $values;
        $this->charactersToEscape = $charactersToEscape;
    }

    public function __toString(): string
    {
        $escapedValues = array_map(function ($value) {
            foreach ($this->charactersToEscape as $character) {
                $value = str_replace($character, "\\$character", $value);
            }
            return $value;
        }, $this->values);

        return sprintf('@%s:{%s}', $this->fieldName, implode('|', $escapedValues));
    }
}