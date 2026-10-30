<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

final class CompletionCursor
{
    private int $offset = 0;
    private const int MAX_BATCH_BYTES = 65_536;

    public function __construct(private readonly string $path) {}

    public function read(EvidenceMode $mode): string
    {
        if (!file_exists($this->path)) {
            return '';
        }
        $stream = fopen($this->path, 'rb');
        Reports::require($stream !== false, 'Cannot read workload completions');
        try {
            $stat = fstat($stream);
            Reports::require(
                is_array($stat) && $stat['size'] >= $this->offset && fseek($stream, $this->offset) === 0,
                'Workload completion log was truncated',
            );
            $contents = stream_get_contents($stream, self::MAX_BATCH_BYTES + 1);
            Reports::require(is_string($contents) && strlen($contents) <= self::MAX_BATCH_BYTES, 'Workload completion batch exceeded its bound');
            Reports::records($contents, $mode);
            $end = strrpos($contents, "\n");
            if ($end === false) {
                return '';
            }
            $complete = substr($contents, 0, $end + 1);
            $this->offset += strlen($complete);
            return $complete;
        } finally {
            fclose($stream);
        }
    }
}
