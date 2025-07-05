<?php

use Swoole\Thread;
use Swoole\Thread\Queue;

// Enhanced error handling and validation
function handleError(Queue $queue, string $message): void
{
    error_log("Count Lines Task Error: $message");
    $queue->push(0);
    exit(1);
}

// Get and validate arguments
$args = Thread::getArguments();
if (count($args) < 4) {
    handleError(new Queue(), "Insufficient arguments provided");
}

[$path, $start, $end, $queue] = $args;

// Validate inputs
if (!is_string($path) || !is_int($start) || !is_int($end) || !($queue instanceof Queue)) {
    handleError($queue, "Invalid argument types");
}

if (!file_exists($path) || !is_readable($path)) {
    handleError($queue, "File does not exist or is not readable: $path");
}

// Open file with error handling
$handle = fopen($path, 'rb');
if (!$handle) {
    handleError($queue, "Cannot open file: $path");
}

try {
    // Seek to start position
    if (fseek($handle, $start) !== 0) {
        throw new RuntimeException("Cannot seek to position $start");
    }

    $lineCount = 0;
    $bytesRead = 0;
    $chunkSize = 8192;
    $maxBytesToRead = $end - $start;

    // Align to line boundary if not at start
    if ($start > 0) {
        while (!feof($handle) && $bytesRead < $maxBytesToRead) {
            $char = fgetc($handle);
            if ($char === false) break;

            $bytesRead++;
            if ($char === "\n") {
                break;
            }
        }
    }

    // Count lines in chunks for better performance
    while (!feof($handle) && $bytesRead < $maxBytesToRead) {
        $remainingBytes = $maxBytesToRead - $bytesRead;
        $readSize = min($chunkSize, $remainingBytes);

        $chunk = fread($handle, $readSize);
        if ($chunk === false) break;

        $chunkLength = strlen($chunk);
        $bytesRead += $chunkLength;
        $lineCount += substr_count($chunk, "\n");

        // Early exit if we've read all requested bytes
        if ($bytesRead >= $maxBytesToRead) {
            break;
        }
    }

    fclose($handle);
    $queue->push($lineCount);

} catch (Exception $e) {
    fclose($handle);
    handleError($queue, "Error processing file: " . $e->getMessage());
}