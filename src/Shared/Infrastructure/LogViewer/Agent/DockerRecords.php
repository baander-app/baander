<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

/** Joins split writes and indented exception continuations while preserving streams. */
final class DockerRecords
{
    /** @var array<string,string> */
    private array $buffers = [];
    /** @var array<string,bool> */
    private array $discarding = [];
    /** @var array<string,array{stream:string,timestamp:string,raw:string}> */
    private array $pending = [];

    /** @param list<array{stream:string,data:string}> $frames
     * @return list<array{stream:string,timestamp:string,raw:string}>
     */
    public static function decode(array $frames): array
    {
        $decoder = new self();

        return array_merge($decoder->feed($frames), $decoder->finish());
    }

    /** @param list<array{stream:string,data:string}> $frames
     * @return list<array{stream:string,timestamp:string,raw:string}>
     */
    public function feed(array $frames): array
    {
        $records = [];
        foreach ($frames as $frame) {
            $stream = $frame['stream'];
            $data = $frame['data'];
            $offset = 0;
            if ($this->discarding[$stream] ?? false) {
                $newline = strpos($data, "\n");
                if (false === $newline) {
                    continue;
                }
                array_push($records, ...$this->consume($stream, $this->buffers[$stream]));
                $this->buffers[$stream] = '';
                $this->discarding[$stream] = false;
                $offset = $newline + 1;
            }
            $this->buffers[$stream] = ($this->buffers[$stream] ?? '').substr($data, $offset);
            $offset = 0;
            while (false !== ($newline = strpos($this->buffers[$stream], "\n", $offset))) {
                array_push($records, ...$this->consume($stream, substr($this->buffers[$stream], $offset, min($newline - $offset, 262272))));
                $offset = $newline + 1;
            }
            $this->buffers[$stream] = substr($this->buffers[$stream], $offset);
            if (strlen($this->buffers[$stream]) > 262272) {
                $this->buffers[$stream] = substr($this->buffers[$stream], 0, 262272);
                $this->discarding[$stream] = true;
            }
        }

        return $records;
    }

    /** @return list<array{stream:string,timestamp:string,raw:string}> */
    public function finish(): array
    {
        $records = [];
        foreach ($this->buffers as $stream => $buffer) {
            array_push($records, ...$this->consume($stream, $buffer));
        }
        array_push($records, ...array_values($this->pending));
        $this->buffers = $this->pending = [];

        return $records;
    }

    public function earliestPendingTimestamp(): ?string
    {
        $timestamps = array_column($this->pending, 'timestamp');
        foreach ($this->buffers as $buffer) {
            $timestamp = strstr($buffer, ' ', true);
            if (false !== $timestamp && false !== strtotime($timestamp)) {
                $timestamps[] = $timestamp;
            }
        }

        return [] === $timestamps ? null : min($timestamps);
    }

    /** @return list<array{stream:string,timestamp:string,raw:string}> */
    private function consume(string $stream, string $line): array
    {
        if ('' === $line) {
            return [];
        }
        $parts = explode(' ', $line, 2);
        $timestamp = count($parts) > 1 && false !== strtotime($parts[0]) ? $parts[0] : gmdate('c');
        $raw = $parts[1] ?? $line;
        if (isset($this->pending[$stream]) && preg_match('/^(?:\s+|#\d+\s|Stack trace:|Caused by:)/', $raw)) {
            $this->pending[$stream]['raw'] = substr($this->pending[$stream]['raw']."\n".$raw, 0, 262145);
            return [];
        }
        $records = isset($this->pending[$stream]) ? [$this->pending[$stream]] : [];
        $this->pending[$stream] = ['stream' => $stream, 'timestamp' => $timestamp, 'raw' => substr($raw, 0, 262145)];

        return $records;
    }
}
