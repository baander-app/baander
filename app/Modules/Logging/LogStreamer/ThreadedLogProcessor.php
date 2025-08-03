<?php

namespace App\Modules\Logging\LogStreamer;

use App\Modules\Logging\LogStreamer\Models\ContentChunk;
use App\Modules\Logging\LogStreamer\Models\SearchResults;
use RuntimeException;
use Swoole\Thread;
use Swoole\Thread\Queue;

class ThreadedLogProcessor
{
    private const MAX_THREADS = 4;
    private const CHUNK_SIZE = 1024 * 1024; // 1MB chunks
    private const MIN_FILE_SIZE_FOR_THREADING = 512 * 1024; // 512KB

    public function __construct(
        private readonly string $filePath
    ) {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("File not found: {$this->filePath}");
        }

        if (!is_readable($this->filePath)) {
            throw new RuntimeException("File is not readable: {$this->filePath}");
        }
    }

    public function countLines(): int
    {
        $fileSize = filesize($this->filePath);

        if ($fileSize === false) {
            throw new RuntimeException("Cannot determine file size: {$this->filePath}");
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

    public function getContentAfterLine(int $lineNumber, ?int $maxLines = null): ContentChunk
    {
        if ($lineNumber < 0) {
            throw new RuntimeException("Line number must be non-negative");
        }

        $totalLines = $this->countLines();
        $queue = new Queue();

        $thread = new Thread(
            __DIR__ . '/Tasks/readContentTask.php',
            $this->filePath,
            $lineNumber,
            $maxLines,
            $queue
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
            totalLines: $totalLines
        );
    }

    public function searchInFile(
        string $pattern,
        bool $caseSensitive = true,
        ?int $maxResults = null
    ): SearchResults {
        $logFile = new SearchableLogFile($this->filePath);
        return $logFile->search($pattern, $caseSensitive, $maxResults);
    }

    private function calculateOptimalThreads(int $fileSize): int
    {
        $threadsFromSize = min(self::MAX_THREADS, max(1, intval($fileSize / self::CHUNK_SIZE)));
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
                    $this->filePath,
                    $start,
                    $end,
                    $queue
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
            return $this->countLinesSequential();
        }
    }

    private function countLinesSequential(): int
    {
        $handle = fopen($this->filePath, 'rb');
        if (!$handle) {
            throw new RuntimeException("Cannot open file: {$this->filePath}");
        }

        $lineCount = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            $lineCount += substr_count($chunk, "\n");
        }

        fclose($handle);
        return $lineCount;
    }
}