<?php

namespace Baander\Transcoder;

use Amp\ByteStream\WritableIterableStream;
use Amp\File;
use League\Container\ContainerAwareTrait;
use Revolt\EventLoop;

class TranscoderLogger
{
    use ContainerAwareTrait;

    private string $buffer = ''; // Buffer in memory
    private int $bufferSize = 0; // Keeps track of current buffer size
    private int $flushSize = 4096; // Max size of buffer before flushing in bytes (e.g., 4KB)
    private string $filePath;
    private \Amp\File\File $fileHandle;         // Writable file handle
    private int $flushIntervalMs = 5000; // Time to flush buffer in milliseconds (e.g., every 5 seconds)
    private bool $isFlushing = false; // Prevent overlapping flushes


    public function __construct()
    {
        $path = $this->container->get(TranscoderContext::class)->transcoderLogfilePath;

        $this->fileHandle = File\openFile($path, 'a');

        EventLoop::repeat($this->flushIntervalMs, function () use ($path) {
            if ($this->bufferSize > $this->flushSize) {
                $this->fileHandle->write($this->buffer);
                $this->buffer = '';
                $this->bufferSize = 0;
            }
        });

        EventLoop::onSignal(SIGINT, function () {
            if (!$this->isFlushing && $this->bufferSize > 0) {
                $this->isFlushing = true;
                $this->fileHandle->write($this->buffer);
                $this->buffer = '';
            }
        });
    }

    public function info(array $message)
    {
        $this->log('info', $message);
    }

    public function error(array $message)
    {
        $this->log('error', $message);
    }

    public function logWriteableStream(WritableIterableStream $stream)
    {
        $stream->getIterator()->each(function ($chunk) {
            $this->log('info', ['chunk' => $chunk]);
        });
    }

    private function log(string $level, array $message)
    {
        $message = json_encode(['level' => $level, ...$message]) . PHP_EOL;
    }
}