<?php

use Swoole\Thread;
use Swoole\Thread\Queue;

// Enhanced error handling
function handleError(Queue $queue, string $message): void
{
    error_log("Read Content Task Error: $message");
    $queue->push('');
    exit(1);
}

// Get and validate arguments
$args = Thread::getArguments();
if (count($args) < 3) {
    handleError(new Queue(), "Insufficient arguments provided");
}

$path = $args[0];
$lineNumber = $args[1];
$maxLines = $args[2] ?? null;
$queue = $args[3] ?? $args[2]; // Handle backward compatibility

// Validate inputs
if (!is_string($path) || !is_int($lineNumber)) {
    handleError($queue, "Invalid argument types");
}

if ($lineNumber < 0) {
    handleError($queue, "Line number must be non-negative");
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
    $currentLine = 0;
    $content = '';
    $linesRead = 0;
    $bufferSize = 8192;

    // Use more efficient line reading
    while (!feof($handle)) {
        $line = fgets($handle, $bufferSize);
        if ($line === false) break;

        $currentLine++;

        // Start collecting content after the specified line
        if ($currentLine > $lineNumber) {
            $content .= $line;
            $linesRead++;

            // Stop if we've reached the maximum lines limit
            if ($maxLines !== null && $linesRead >= $maxLines) {
                break;
            }
        }
    }

    fclose($handle);
    $queue->push($content);

} catch (Exception $e) {
    fclose($handle);
    handleError($queue, "Error reading file: " . $e->getMessage());
}