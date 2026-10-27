<?php

declare(strict_types=1);

namespace Baander\Tooling\CommandLine;

final class Words
{
    /** @return list<string> */
    public static function split(string $command): array
    {
        $words = [];
        $word = '';
        $started = false;
        $quote = null;
        $length = strlen($command);

        for ($index = 0; $index < $length; ++$index) {
            $character = $command[$index];
            if ($character === '\\' && $quote !== "'") {
                if (++$index === $length) {
                    throw new \InvalidArgumentException('No escaped character');
                }
                $escaped = $command[$index];
                if ($quote === '"' && $escaped !== '"' && $escaped !== '\\') {
                    $word .= '\\';
                }
                $word .= $escaped;
                $started = true;
            } elseif ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } else {
                    $word .= $character;
                }
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
                $started = true;
            } elseif (str_contains(" \t\r\n", $character)) {
                if ($started) {
                    $words[] = $word;
                    $word = '';
                    $started = false;
                }
            } else {
                $word .= $character;
                $started = true;
            }
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException('No closing quotation');
        }
        if ($started) {
            $words[] = $word;
        }

        return $words;
    }
}
