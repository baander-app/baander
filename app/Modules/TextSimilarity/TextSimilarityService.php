<?php

namespace App\Modules\TextSimilarity;

use InvalidArgumentException;
use Normalizer;

class TextSimilarityService
{
    private array $transliterationCache = [];
    private array $scriptCache = [];
    private array $ngramCache = [];

    private const int CACHE_SIZE_LIMIT = 1000;
    private const int DEFAULT_NGRAM_SIZE = 2;
    private const array SIMILARITY_THRESHOLDS = [
        'EXACT'     => 100.0,
        'VERY_HIGH' => 95.0,
        'HIGH'      => 85.0,
        'MEDIUM'    => 70.0,
        'LOW'       => 50.0,
    ];

    /**
     * Calculate the best similarity score between two texts using multiple algorithms.
     */
    public function calculateSimilarity(string $text1, string $text2): float
    {
        if (empty($text1) || empty($text2)) {
            return 0.0;
        }

        if ($text1 === $text2) {
            return 100.0;
        }

        $cacheKey = $this->makeCacheKey($text1, $text2);
        if (isset($this->ngramCache[$cacheKey])) {
            return $this->ngramCache[$cacheKey];
        }

        $maxLen = max(mb_strlen($text1, 'UTF-8'), mb_strlen($text2, 'UTF-8'));
        if ($maxLen == 0) return 100.0;

        $similarities = [];

        // Quick similarity check first (fastest)
        $similarities['similar_text'] = 0;
        similar_text($text1, $text2, $similarities['similar_text']);

        // Early return if we have a very high similarity
        if ($similarities['similar_text'] >= self::SIMILARITY_THRESHOLDS['VERY_HIGH']) {
            return $this->cacheResult($cacheKey, $similarities['similar_text']);
        }

        // More expensive algorithms only if needed
        $similarities['levenshtein'] = $this->calculateUnicodeLevenshtein($text1, $text2, $maxLen);
        $similarities['lcs'] = $this->longestCommonSubsequenceSimilarity($text1, $text2);
        $similarities['ngram'] = $this->calculateNGramSimilarity($text1, $text2);

        $bestScore = max($similarities);
        return $this->cacheResult($cacheKey, round(max(0, min(100, $bestScore)), 2));
    }

    public function calculateInternationalNameSimilarity(string $name1, string $name2): array
    {
        $cacheKey = 'intl_' . $this->makeCacheKey($name1, $name2);
        if (isset($this->ngramCache[$cacheKey])) {
            return $this->ngramCache[$cacheKey];
        }

        $scores = [];

        // 1. Direct similarity after Unicode normalization
        $normalizedName1 = $this->normalizeInternationalText($name1);
        $normalizedName2 = $this->normalizeInternationalText($name2);
        $scores['direct'] = $this->calculateSimilarity($normalizedName1, $normalizedName2);

        // Skip expensive operations if we have a perfect match
        if ($scores['direct'] >= 99.0) {
            $scores['transliterated'] = $scores['direct'];
            $scores['token'] = $scores['direct'];
            $scores['romanized'] = $scores['direct'];
            $scores['unicode'] = $scores['direct'];
            return $this->cacheResult($cacheKey, $scores);
        }

        // 2. Additional similarity methods
        $scores['transliterated'] = $this->calculateTransliterationSimilarity($name1, $name2);
        $scores['token'] = $this->calculateTokenSimilarity($name1, $name2);
        $scores['romanized'] = $this->calculateRomanizationSimilarity($name1, $name2);
        $scores['unicode'] = $this->calculateUnicodeScriptSimilarity($name1, $name2);

        return $this->cacheResult($cacheKey, $scores);
    }

    /**
     * Get similarity confidence level
     */
    public function getSimilarityLevel(float $score): string
    {
        return match (true) {
            $score >= self::SIMILARITY_THRESHOLDS['EXACT'] => 'EXACT',
            $score >= self::SIMILARITY_THRESHOLDS['VERY_HIGH'] => 'VERY_HIGH',
            $score >= self::SIMILARITY_THRESHOLDS['HIGH'] => 'HIGH',
            $score >= self::SIMILARITY_THRESHOLDS['MEDIUM'] => 'MEDIUM',
            $score >= self::SIMILARITY_THRESHOLDS['LOW'] => 'LOW',
            default => 'VERY_LOW'
        };
    }

    /**
     * Batch similarity calculation for multiple comparisons
     */
    public function calculateBatchSimilarity(string $target, array $candidates): array
    {
        $results = array_map(function ($candidate) use ($target) {
            return [
                'text'       => $candidate,
                'similarity' => $this->calculateSimilarity($target, $candidate),
            ];
        }, $candidates);

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return $results;
    }

    /**
     * Find the best matches above a threshold
     */
    public function findBestMatches(string $target, array $candidates, float $threshold = 70.0, int $limit = 5): array
    {
        $results = $this->calculateBatchSimilarity($target, $candidates);

        return array_slice(
            array_filter($results, fn($result) => $result['similarity'] >= $threshold),
            0,
            $limit,
        );
    }

    public function getBestInternationalSimilarity(string $name1, string $name2): float
    {
        $scores = $this->calculateInternationalNameSimilarity($name1, $name2);

        if ($scores['direct'] >= 85) {
            return $scores['direct'];
        }

        return max($scores);
    }

    public function normalizeInternationalText(string $text): string
    {
        $cacheKey = 'normalize_' . hash('xxh3', $text);
        if (isset($this->transliterationCache[$cacheKey])) {
            return $this->transliterationCache[$cacheKey];
        }

        if (class_exists('Normalizer')) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC);
        }

        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $text);
        $text = preg_replace('/[\p{Z}\p{C}]+/u', ' ', $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\p{P}\p{S}]/u', '', $text);

        $normalized = trim($text);
        return $this->cacheTransliteration($cacheKey, $normalized);
    }

    public function extractInternationalTokens(string $text): array
    {
        $tokens = preg_split('/[\p{Z}\p{P}\p{S}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_filter($tokens, function ($token) {
            $length = mb_strlen($token, 'UTF-8');
            if ($length === 1) {
                return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $token);
            }

            return $length > 1;
        });
    }

    public function transliterateToLatin(string $text): string
    {
        $cacheKey = 'transliterate_' . hash('xxh3', $text);
        if (isset($this->transliterationCache[$cacheKey])) {
            return $this->transliterationCache[$cacheKey];
        }

        $result = $this->performTransliteration($text);

        return $this->cacheTransliteration($cacheKey, $result);
    }

    public function getUnicodeScripts(string $text): array
    {
        $cacheKey = 'scripts_' . hash('xxh3', $text);
        if (isset($this->scriptCache[$cacheKey])) {
            return $this->scriptCache[$cacheKey];
        }

        $scripts = [];
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            if (preg_match('/\p{Latin}/u', $char)) {
                $scripts['Latin'] = true;
            } else if (preg_match('/\p{Han}/u', $char)) {
                $scripts['Han'] = true;
            } else if (preg_match('/\p{Hiragana}/u', $char)) {
                $scripts['Hiragana'] = true;
            } else if (preg_match('/\p{Katakana}/u', $char)) {
                $scripts['Katakana'] = true;
            } else if (preg_match('/\p{Cyrillic}/u', $char)) {
                $scripts['Cyrillic'] = true;
            } else if (preg_match('/\p{Arabic}/u', $char)) {
                $scripts['Arabic'] = true;
            } else if (preg_match('/\p{Hebrew}/u', $char)) {
                $scripts['Hebrew'] = true;
            } else if (preg_match('/\p{Greek}/u', $char)) {
                $scripts['Greek'] = true;
            } else if (preg_match('/\p{Devanagari}/u', $char)) {
                $scripts['Devanagari'] = true;
            }
        }

        $result = array_keys($scripts);

        return $this->cacheScript($cacheKey, $result);
    }

    public function hasNonLatinScript(string $text): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Arabic}\p{Hebrew}\p{Cyrillic}]/u', $text);
    }

    public function calculateNGramSimilarity(string $str1, string $str2, int $n = self::DEFAULT_NGRAM_SIZE): float
    {
        $ngrams1 = $this->extractNGrams($str1, $n);
        $ngrams2 = $this->extractNGrams($str2, $n);

        if (empty($ngrams1) || empty($ngrams2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($ngrams1, $ngrams2));
        $union = count(array_unique(array_merge($ngrams1, $ngrams2)));

        return $union > 0 ? ($intersection / $union) * 100 : 0.0;
    }

    public function calculateWeightedAverage(array $scores, array $weights): float
    {
        if (count($scores) !== count($weights)) {
            throw new InvalidArgumentException('Scores and weights arrays must have the same length');
        }

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($scores as $i => $score) {
            $weight = $weights[$i];
            $weightedSum += $score * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }

    public function clearCaches(): void
    {
        $this->transliterationCache = [];
        $this->scriptCache = [];
        $this->ngramCache = [];
    }

    private function makeCacheKey(string $str1, string $str2): string
    {
        return hash('xxh3', $str1 . '|' . $str2);
    }

    private function cacheResult(string $key, mixed $result): mixed
    {
        if (count($this->ngramCache) >= self::CACHE_SIZE_LIMIT) {
            $this->ngramCache = array_slice($this->ngramCache, -500, null, true);
        }

        $this->ngramCache[$key] = $result;

        return $result;
    }

    private function cacheTransliteration(string $key, string $result): string
    {
        if (count($this->transliterationCache) >= self::CACHE_SIZE_LIMIT) {
            $this->transliterationCache = array_slice($this->transliterationCache, -500, null, true);
        }

        $this->transliterationCache[$key] = $result;
        return $result;
    }

    private function cacheScript(string $key, array $result): array
    {
        if (count($this->scriptCache) >= self::CACHE_SIZE_LIMIT) {
            $this->scriptCache = array_slice($this->scriptCache, -500, null, true);
        }

        $this->scriptCache[$key] = $result;

        return $result;
    }

    private function performTransliteration(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            $transliterators = [
                'Any-Latin',
                'Any-Latin; Latin-ASCII',
                'Cyrillic-Latin',
                'Greek-Latin',
                'Han-Latin',
                'Hiragana-Latin',
                'Katakana-Latin',
                'Arabic-Latin',
                'Hebrew-Latin',
            ];

            foreach ($transliterators as $rule) {
                $result = @transliterator_transliterate($rule, $text);
                if ($result !== false && $result !== $text) {
                    return $this->normalizeInternationalText($result);
                }
            }
        }

        return $this->fallbackTransliteration($text);
    }

    private function calculateTransliterationSimilarity(string $name1, string $name2): float
    {
        $transliterated1 = $this->transliterateToLatin($name1);
        $transliterated2 = $this->transliterateToLatin($name2);

        if ($transliterated1 !== $name1 || $transliterated2 !== $name2) {
            return $this->calculateSimilarity($transliterated1, $transliterated2) * 0.85;
        }

        return 0.0;
    }

    private function calculateTokenSimilarity(string $name1, string $name2): float
    {
        $tokens1 = $this->extractInternationalTokens($name1);
        $tokens2 = $this->extractInternationalTokens($name2);

        if (empty($tokens1) || empty($tokens2)) {
            return 0.0;
        }

        $normalizedTokens1 = array_map([$this, 'normalizeInternationalText'], $tokens1);
        $normalizedTokens2 = array_map([$this, 'normalizeInternationalText'], $tokens2);

        $intersection = count(array_intersect($normalizedTokens1, $normalizedTokens2));
        $union = count(array_unique(array_merge($normalizedTokens1, $normalizedTokens2)));

        return $union > 0 ? ($intersection / $union) * 100 : 0.0;
    }

    private function calculateRomanizationSimilarity(string $name1, string $name2): float
    {
        $hasNonLatin1 = $this->hasNonLatinScript($name1);
        $hasNonLatin2 = $this->hasNonLatinScript($name2);

        if (!$hasNonLatin1 && !$hasNonLatin2) {
            return 0.0;
        }

        $romanized1 = $this->transliterateToLatin($name1);
        $romanized2 = $this->transliterateToLatin($name2);

        if ($romanized1 !== $name1 || $romanized2 !== $name2) {
            return $this->calculateSimilarity($romanized1, $romanized2) * 0.8;
        }

        return 0.0;
    }

    private function calculateUnicodeScriptSimilarity(string $name1, string $name2): float
    {
        $scripts1 = $this->getUnicodeScripts($name1);
        $scripts2 = $this->getUnicodeScripts($name2);

        $commonScripts = array_intersect($scripts1, $scripts2);
        if (empty($commonScripts)) {
            return 0.0;
        }

        if (count($commonScripts) === 1 && count($scripts1) === 1 && count($scripts2) === 1) {
            return $this->calculateSimilarity(
                $this->normalizeInternationalText($name1),
                $this->normalizeInternationalText($name2),
            );
        }

        $scriptOverlap = count($commonScripts) / max(count($scripts1), count($scripts2));

        return $this->calculateSimilarity(
                $this->normalizeInternationalText($name1),
                $this->normalizeInternationalText($name2),
            ) * $scriptOverlap;
    }

    private function calculateUnicodeLevenshtein(string $str1, string $str2, int $maxLen): float
    {
        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');

        if ($len1 === 0) return $len2 > 0 ? 0 : 100;
        if ($len2 === 0) return 0;

        if (mb_check_encoding($str1, 'ASCII') && mb_check_encoding($str2, 'ASCII')) {
            $distance = levenshtein($str1, $str2);
        } else {
            $distance = $this->unicodeLevenshteinDistance($str1, $str2);
        }

        $similarity = (($maxLen - $distance) / $maxLen) * 100;
        return max(0, $similarity);
    }

    private function unicodeLevenshteinDistance(string $str1, string $str2): int
    {
        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');

        $matrix = [];

        for ($i = 0; $i <= $len1; $i++) {
            $matrix[$i][0] = $i;
        }
        for ($j = 0; $j <= $len2; $j++) {
            $matrix[0][$j] = $j;
        }

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $char1 = mb_substr($str1, $i - 1, 1, 'UTF-8');
                $char2 = mb_substr($str2, $j - 1, 1, 'UTF-8');

                $cost = ($char1 === $char2) ? 0 : 1;

                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,
                    $matrix[$i][$j - 1] + 1,
                    $matrix[$i - 1][$j - 1] + $cost,
                );
            }
        }

        return $matrix[$len1][$len2];
    }

    private function longestCommonSubsequenceSimilarity(string $str1, string $str2): float
    {
        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');

        if ($len1 == 0 || $len2 == 0) {
            return 0.0;
        }

        $lcs = $this->unicodeLongestCommonSubsequence($str1, $str2);
        return (2.0 * $lcs) / ($len1 + $len2) * 100;
    }

    private function unicodeLongestCommonSubsequence(string $str1, string $str2): int
    {
        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');
        $dp = array_fill(0, $len1 + 1, array_fill(0, $len2 + 1, 0));

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $char1 = mb_substr($str1, $i - 1, 1, 'UTF-8');
                $char2 = mb_substr($str2, $j - 1, 1, 'UTF-8');

                if ($char1 === $char2) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        return $dp[$len1][$len2];
    }

    private function extractNGrams(string $text, int $n): array
    {
        $length = mb_strlen($text, 'UTF-8');
        $ngrams = [];

        for ($i = 0; $i <= $length - $n; $i++) {
            $ngrams[] = mb_substr($text, $i, $n, 'UTF-8');
        }

        return $ngrams;
    }

    private function fallbackTransliteration(string $text): string
    {
        $replacements = [
            // Cyrillic
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'yo',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'h',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',

            // Greek
            'α' => 'a',
            'β' => 'b',
            'γ' => 'g',
            'δ' => 'd',
            'ε' => 'e',
            'ζ' => 'z',
            'η' => 'i',
            'θ' => 'th',
            'ι' => 'i',
            'κ' => 'k',
            'λ' => 'l',
            'μ' => 'm',
            'ν' => 'n',
            'ξ' => 'x',
            'ο' => 'o',
            'π' => 'p',
            'ρ' => 'r',
            'σ' => 's',
            'ς' => 's',
            'τ' => 't',
            'υ' => 'y',
            'φ' => 'f',
            'χ' => 'ch',
            'ψ' => 'ps',
            'ω' => 'o',

            // Common diacritics
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'ç' => 'c',
        ];

        return strtr(mb_strtolower($text, 'UTF-8'), $replacements);
    }
}