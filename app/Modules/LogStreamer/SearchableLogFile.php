<?php

namespace App\Modules\LogStreamer;

use App\Modules\LogStreamer\Models\ContentChunk;
use App\Modules\LogStreamer\Models\FileInfo;
use App\Modules\LogStreamer\Models\SearchResult;
use App\Modules\LogStreamer\Models\SearchResults;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Swoole\Thread;
use Swoole\Thread\Queue;

class SearchableLogFile
{
    private const DEFAULT_CHUNK_SIZE = 8192;
    private const MB_SIZE = 1024 * 1024;
    private const MAX_THREADS = 4;
    private const MIN_FILE_SIZE_FOR_THREADING = 512 * 1024; // 512KB

    public function __construct(
        public readonly string $path,
    )
    {
        if (!file_exists($this->path)) {
            throw new RuntimeException("File not found: {$this->path}");
        }

        if (!is_readable($this->path)) {
            throw new RuntimeException("File is not readable: {$this->path}");
        }
    }

    public function numberOfLines(): int
    {
        $fileSize = filesize($this->path);

        if ($fileSize === false) {
            throw new RuntimeException("Cannot determine file size: {$this->path}");
        }

        if ($fileSize < self::MIN_FILE_SIZE_FOR_THREADING) {
            return $this->countLinesSequential();
        }

        $numThreads = $this->calculateOptimalThreads($fileSize);

        if ($numThreads === 1) {
            return $this->countLinesSequential();
        }

        return $this->countLinesParallel($numThreads, $fileSize);
    }

    public function contentAfterLine(int $lineNumber, ?int $maxLines = null): ContentChunk
    {
        if ($lineNumber < 0) {
            throw new RuntimeException("Line number must be non-negative");
        }

        $totalLines = $this->numberOfLines();
        $queue = new Queue();

        $thread = new Thread(
            __DIR__ . '/Tasks/readContentTask.php',
            $this->path,
            $lineNumber,
            $maxLines,
            $queue,
        );

        $thread->join();

        if ($queue->count() === 0) {
            throw new RuntimeException("Thread failed to return content");
        }

        $content = $queue->pop();
        $actualLines = substr_count($content, "\n");
        $endLine = min($lineNumber + ($maxLines ?? $actualLines), $totalLines);

        return ContentChunk::create(
            content: $content,
            startLine: $lineNumber + 1,
            endLine: $endLine,
            totalLines: $totalLines,
        );
    }

    public function getFileInfo(): FileInfo
    {
        $size = filesize($this->path);
        $lines = $this->numberOfLines();

        return FileInfo::create(
            path: $this->path,
            size: $size,
            lines: $lines,
            optimalThreads: $this->calculateOptimalThreads($size),
        );
    }

    public function search(
        string $pattern,
        bool   $caseSensitive = true,
        ?int   $maxResults = null,
    ): SearchResults
    {
        $startTime = microtime(true);
        $results = [];
        $handle = fopen($this->path, 'rb');

        if (!$handle) {
            throw new RuntimeException("Cannot open file: {$this->path}");
        }

        $lineNumber = 0;
        $resultCount = 0;
        $hasMoreResults = false;

        while (!feof($handle) && ($maxResults === null || $resultCount < $maxResults)) {
            $line = fgets($handle);
            if ($line === false) break;

            $lineNumber++;

            $searchLine = $caseSensitive ? $line : strtolower($line);
            $searchPattern = $caseSensitive ? $pattern : strtolower($pattern);

            if (str_contains($searchLine, $searchPattern)) {
                $results[] = SearchResult::create(
                    line: $lineNumber,
                    content: rtrim($line),
                    position: ftell($handle) - strlen($line),
                );
                $resultCount++;
            }
        }

        // Check if there are more results
        if ($maxResults !== null && $resultCount === $maxResults) {
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) break;

                $searchLine = $caseSensitive ? $line : strtolower($line);
                $searchPattern = $caseSensitive ? $pattern : strtolower($pattern);

                if (str_contains($searchLine, $searchPattern)) {
                    $hasMoreResults = true;
                    break;
                }
            }
        }

        fclose($handle);

        $searchTime = (microtime(true) - $startTime) * 1000;

        return SearchResults::create(
            results: $results,
            pattern: $pattern,
            caseSensitive: $caseSensitive,
            searchTimeMs: $searchTime,
            hasMoreResults: $hasMoreResults,
        );
    }

    private function calculateOptimalThreads(int $fileSize): int
    {
        $threadsFromSize = min(self::MAX_THREADS, max(1, intval($fileSize / self::MB_SIZE)));
        $availableCores = min(Thread::HARDWARE_CONCURRENCY, self::MAX_THREADS);

        return min($threadsFromSize, $availableCores);
    }

    private function countLinesParallel(int $numThreads, int $fileSize): int
    {
        $chunkSize = intval($fileSize / $numThreads);
        $queue = new Queue();
        $threads = [];

        try {
            for ($i = 0; $i < $numThreads; $i++) {
                $start = $i * $chunkSize;
                $end = ($i === $numThreads - 1) ? $fileSize : ($i + 1) * $chunkSize;

                $threads[] = new Thread(
                    __DIR__ . '/Tasks/countLinesTask.php',
                    $this->path,
                    $start,
                    $end,
                    $queue,
                );
            }

            foreach ($threads as $thread) {
                $thread->join();
            }

            $totalLines = 0;
            for ($i = 0; $i < $numThreads; $i++) {
                if ($queue->count() > 0) {
                    $totalLines += $queue->pop();
                }
            }

            return $totalLines;
        } catch (\Exception $e) {
            Log::error("Error counting lines in file: {$this->path}", [
                'e.message' => $e->getMessage(),
            ]);

            return $this->countLinesSequential();
        }
    }

    private function countLinesSequential(): int
    {
        $handle = fopen($this->path, 'rb');
        if (!$handle) {
            throw new RuntimeException("Cannot open file: {$this->path}");
        }

        $lineCount = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, self::DEFAULT_CHUNK_SIZE);
            if ($chunk === false) break;
            $lineCount += substr_count($chunk, "\n");
        }

        fclose($handle);
        return $lineCount;
    }
}