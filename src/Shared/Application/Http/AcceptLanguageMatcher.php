<?php

declare(strict_types=1);

namespace App\Shared\Application\Http;

use App\Shared\Domain\Model\Setting\SupportedLanguages;

/**
 * Picks the language a browser ranks highest among those Baander speaks.
 *
 * Request::getPreferredLanguage() is not used because it answers with the first
 * supported language when the browser asks for none of them.
 */
final class AcceptLanguageMatcher
{
    public function match(?string $header): ?string
    {
        $best = null;
        $bestWeight = 0.0;

        foreach (explode(',', $header ?? '') as $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $language = strtolower(explode('-', $parts[0])[0]);
            $weight = $this->weight(array_slice($parts, 1));

            if ($weight === null || $weight <= $bestWeight || !in_array($language, SupportedLanguages::codes(), true)) {
                continue;
            }

            $best = $language;
            $bestWeight = $weight;
        }

        return $best;
    }

    /**
     * @param list<string> $parameters
     */
    private function weight(array $parameters): ?float
    {
        foreach ($parameters as $parameter) {
            if (str_starts_with(strtolower($parameter), 'q=')) {
                $value = substr($parameter, 2);

                return is_numeric($value) ? (float) $value : null;
            }
        }

        return 1.0;
    }
}
