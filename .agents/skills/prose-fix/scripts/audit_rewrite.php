#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Baander\ProseAudit;

/** @return array<string|int, int> */
function tokens(string $pattern, string $text): array
{
    if (preg_match_all($pattern, $text, $matches) === false) {
        throw new \RuntimeException('Audit input must contain valid UTF-8 text.');
    }
    return array_count_values($matches[0]);
}

/** @param array<string|int, int> $before
 * @param array<string|int, int> $after
 * @return array<string|int, int>
 */
function difference(array $before, array $after): array
{
    $result = [];
    foreach ($before as $token => $count) {
        $remaining = $count - ($after[$token] ?? 0);
        if ($remaining > 0) {
            $result[$token] = $remaining;
        }
    }
    return $result;
}

function quoted(string $text): string
{
    $quote = str_contains($text, "'") && !str_contains($text, '"') ? '"' : "'";
    $result = $quote;
    foreach (preg_split('//u', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        if ($character === '\\' || $character === $quote) {
            $result .= '\\' . $character;
        } elseif (isset(["\n" => 'n', "\r" => 'r', "\t" => 't'][$character])) {
            $result .= '\\' . ["\n" => 'n', "\r" => 'r', "\t" => 't'][$character];
        } elseif ($character !== ' ' && preg_match('/[\p{C}\p{Z}]/u', $character) === 1) {
            $bytes = array_values(unpack('C*', $character));
            $point = count($bytes) === 1 ? $bytes[0] : ($bytes[0] & (0xff >> (count($bytes) + 1)));
            foreach (array_slice($bytes, 1) as $byte) {
                $point = ($point << 6) | ($byte & 0x3f);
            }
            $result .= $point <= 0xff ? sprintf('\\x%02x', $point) : ($point <= 0xffff ? sprintf('\\u%04x', $point) : sprintf('\\U%08x', $point));
        } else {
            $result .= $character;
        }
    }
    return $result . $quote;
}

/** @param array<string|int, int> $counts */
function dictionary(array $counts): string
{
    $entries = [];
    foreach ($counts as $token => $count) {
        $entries[] = quoted((string) $token) . ': ' . $count;
    }
    return '{' . implode(', ', $entries) . '}';
}

/** @return list<string> */
function structuralLines(string $text): array
{
    return explode("\n", $text)
        |> (static fn(array $lines): array => array_filter($lines, static fn(string $line): bool => preg_match('/(*UCP)^[\s\x1c-\x1f]*$/u', $line) === 1 || str_starts_with($line, '#') || str_starts_with($line, '```') || str_starts_with($line, '---') || str_starts_with($line, '|')))
        |> array_values(...);
}

function readText(string $path): string
{
    $text = file_get_contents($path);
    if ($text === false) {
        throw new \RuntimeException('Cannot read audit input: ' . $path);
    }
    return str_replace(["\r\n", "\r"], "\n", $text);
}

function report(string $old, string $new): string
{
    $oldTokens = tokens('/`[^`\n]+`/u', $old);
    $newTokens = tokens('/`[^`\n]+`/u', $new);
    $lost = difference($oldTokens, $newTokens);
    $gained = difference($newTokens, $oldTokens);
    $lines = [
        'backtick tokens lost:   ' . dictionary($lost),
        'backtick tokens gained: ' . dictionary($gained),
    ];
    $problems = (int) ($lost !== [] || $gained !== []);
    $oldNumbers = tokens('/(*UCP)\d+(?:\.\d+)*/u', $old);
    $newNumbers = tokens('/(*UCP)\d+(?:\.\d+)*/u', $new);
    $lines[] = 'numbers lost:   ' . dictionary(difference($oldNumbers, $newNumbers));
    $lines[] = 'numbers gained: ' . dictionary(difference($newNumbers, $oldNumbers));

    // Match the original audit's Unicode case-insensitive word boundaries.
    $foldedOld = str_replace(['İ', 'ı', 'ſ', 'K'], ['i', 'i', 's', 'k'], $old);
    $foldedNew = str_replace(['İ', 'ı', 'ſ', 'K'], ['i', 'i', 's', 'k'], $new);
    foreach (['must', 'never', 'cannot', "can't", 'always', 'only', 'required', 'shall'] as $word) {
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($word, '/') . '(?![\p{L}\p{N}_])/iu';
        $before = array_sum(tokens($pattern, $foldedOld));
        $after = array_sum(tokens($pattern, $foldedNew));
        $flag = $before === $after ? '' : '   <<< DELTA -- trace to a line, justify or revert';
        $problems += (int) ($before !== $after);
        $lines[] = "{$word}: {$before} -> {$after}{$flag}";
    }
    foreach (['code fences' => '/^```/m', 'table rows' => '/^\|/m', 'headers' => '/^#/m'] as $label => $pattern) {
        $before = array_sum(tokens($pattern, $old));
        $after = array_sum(tokens($pattern, $new));
        $flag = $before === $after ? '' : '   <<< DELTA';
        $problems += (int) ($before !== $after);
        $lines[] = "{$label}: {$before} -> {$after}{$flag}";
    }
    $before = substr_count($old, '§');
    $after = substr_count($new, '§');
    $flag = $before === $after ? '' : '   (review: added refs must state already-true relations)';
    $lines[] = "§ references: {$before} -> {$after}{$flag}";
    if (structuralLines($old) === structuralLines($new)) {
        $lines[] = 'structural lines: identical';
    } else {
        ++$problems;
        $lines[] = 'structural lines: CHANGED  <<< headers/fences/tables/blanks must not move';
    }
    $lines[] = '';
    $lines[] = 'audit deltas needing explanation: ' . $problems;
    return implode("\n", $lines) . "\n";
}

$arguments = $_SERVER['argv'];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php audit_rewrite.php OLD.md NEW.md\nReports fact-preservation differences; audit findings always return zero.\n");
    exit(1);
}
try {
    echo report(readText($arguments[1]), readText($arguments[2]));
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
