<?php

namespace App\Modules\Metadata\Matching\Validators;

use App\Format\TextSimilarity;
use App\Models\BaseModel;

abstract class BaseQualityValidator
{
    protected TextSimilarity $textSimilarity;

    public function __construct(TextSimilarity $textSimilarity)
    {
        $this->textSimilarity = $textSimilarity;
    }

    abstract public function scoreMatch(array $metadata, BaseModel $model): float;
    abstract public function isValidMatch(array $metadata, float $qualityScore): bool;
    abstract public function isHighConfidenceMatch(array $metadata, BaseModel $model, float $qualityScore): bool;

    /**
     * Enhanced international string similarity
     */
    protected function calculateStringSimilarity(string $str1, string $str2): float
    {
        return $this->textSimilarity->calculateSimilarity($str1, $str2);
    }

    /**
     * Normalize text for international comparison
     */
    protected function normalizeText(string $text): string
    {
        return $this->textSimilarity->normalizeInternationalText($text);
    }

    protected function hasAnyField(array $data, array $fields): bool
    {
        return array_any($fields, fn($field) => !empty($data[$field]));
    }
}