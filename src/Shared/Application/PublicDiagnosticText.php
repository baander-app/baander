<?php

declare(strict_types=1);

namespace App\Shared\Application;

/** Diagnostic text is not an entity reference contract. */
final class PublicDiagnosticText
{
    public static function redact(string $text): string
    {
        return preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[internal ID]', $text) ?? $text;
    }

    /** @param array<array-key, mixed> $details
     *  @return array<array-key, mixed>
     */
    public static function details(array $details): array
    {
        foreach ($details as $key => $value) {
            $details[$key] = is_string($value) ? self::redact($value) : (is_array($value) ? self::details($value) : $value);
        }

        return $details;
    }
}
