<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

final class LogTerminalInput
{
    private string $buffer = '';

    /** @return list<string> */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $keys = [];
        $escapes = ["\033[A" => 'up', "\033[B" => 'down', "\033[5~" => 'page-up', "\033[6~" => 'page-down', "\033[H" => 'home', "\033[F" => 'end', "\033[1~" => 'home', "\033[4~" => 'end'];
        while ($this->buffer !== '') {
            if ($this->buffer[0] === "\033") {
                foreach ($escapes as $sequence => $key) {
                    if (str_starts_with($this->buffer, $sequence)) {
                        $keys[] = $key;
                        $this->buffer = substr($this->buffer, strlen($sequence));
                        continue 2;
                    }
                }
                foreach (array_keys($escapes) as $sequence) {
                    if (str_starts_with($sequence, $this->buffer)) {
                        return $keys;
                    }
                }
                $this->buffer = substr($this->buffer, 1);
                continue;
            }
            if (preg_match('/^./us', $this->buffer, $match) !== 1) {
                return $keys;
            }
            $keys[] = $match[0];
            $this->buffer = substr($this->buffer, strlen($match[0]));
        }

        return $keys;
    }
}
