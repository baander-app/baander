<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

/** Keeps an oversized catch-up response open across bounded collector cycles. */
final class DockerLogSession
{
    private readonly \Generator $chunks;
    private readonly DockerFrameDecoder $frames;
    private readonly DockerRecords $records;
    private bool $started = false;
    private bool $advance = false;
    private ?string $latestTimestamp = null;

    public function __construct(DockerClient $client, string $path, private readonly bool $tty, private readonly ?string $resumeTimestamp = null)
    {
        $this->chunks = $client->chunks($path);
        $this->frames = new DockerFrameDecoder();
        $this->records = new DockerRecords();
    }

    /** @param callable(array{stream:string,timestamp:string,raw:string,checkpointTimestamp:string}):void $consume */
    public function collect(callable $consume, int $byteBudget = 16777216, float $seconds = 1.0): bool
    {
        $bytes = 0;
        $deadline = microtime(true) + $seconds;
        do {
            if (!$this->started) {
                $this->chunks->rewind();
                $this->started = true;
            } elseif ($this->advance) {
                $this->chunks->next();
            }
            if (!$this->chunks->valid()) {
                if (!$this->tty) {
                    $this->frames->finish();
                }
                $this->emit($this->records->finish(), $consume);
                return true;
            }
            $chunk = $this->chunks->current();
            $bytes += strlen($chunk);
            $frames = $this->tty ? [['stream' => 'stdout', 'data' => $chunk]] : $this->frames->push($chunk);
            $this->emit($this->records->feed($frames), $consume);
            $this->advance = true;
        } while ($bytes < $byteBudget && microtime(true) < $deadline);

        return false;
    }
    public function checkpointTimestamp(): ?string
    {
        $timestamp = $this->records->earliestPendingTimestamp() ?? $this->latestTimestamp;
        if (!$this->tty && $this->frames->hasIncompleteFrame()) {
            return min($timestamp ?? $this->resumeTimestamp ?? '1970-01-01T00:00:00Z', $this->resumeTimestamp ?? '1970-01-01T00:00:00Z');
        }
        return $timestamp;
    }

    /** @param list<array{stream:string,timestamp:string,raw:string}> $records
     * @param callable(array{stream:string,timestamp:string,raw:string,checkpointTimestamp:string}):void $consume
     */
    private function emit(array $records, callable $consume): void
    {
        if ([] === $records) {
            return;
        }
        $watermark = min(array_column($records, 'timestamp'));
        $pending = $this->records->earliestPendingTimestamp();
        if (null !== $pending) {
            $watermark = min($watermark, $pending);
        }
        if (!$this->tty && $this->frames->hasIncompleteFrame()) {
            $watermark = min($watermark, $this->resumeTimestamp ?? '1970-01-01T00:00:00Z');
        }
        foreach ($records as $record) {
            $this->latestTimestamp = null === $this->latestTimestamp ? $record['timestamp'] : max($this->latestTimestamp, $record['timestamp']);
            $record['checkpointTimestamp'] = $watermark;
            $consume($record);
        }
    }

}
