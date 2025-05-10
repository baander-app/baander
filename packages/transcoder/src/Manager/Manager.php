<?php

namespace Baander\Transcoder\Manager;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\File;
use Baander\Common\Streaming\TranscodeOptions;
use Baander\Transcoder\Sync\RWMutex;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Manager
{
    private RWMutex $readyMu;
    private RWMutex $segmentsMu;
    private RWMutex $segmentQueueMu;

    private LoggerInterface $logger;
    private TranscodeOptions $config;

    private float $segmentLength = 10.0;
    private float $segmentOffset = 1.0;
    private int $segmentBufferMin = 3;
    private int $segmentBufferMax = 5;

    private bool $ready = false;
    private ?DeferredFuture $readyChan = null;

    private ?ProbeMediaData $metadata = null;
    private string $playlist = '';
    private array $breakpoints = [];
    private array $segments = [];
    private array $segmentQueue = [];

    public function __construct(TranscodeOptions $config, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = $config;

        $this->readyMu = new RWMutex();
        $this->segmentsMu = new RWMutex();
        $this->segmentQueueMu = new RWMutex();
    }

    //
    // Ready State Management
    //
    public function resetReady(): void
    {
        $lock = $this->readyMu->acquireWrite();
        try {
            $this->ready = false;
            $this->readyChan = new DeferredFuture();
        } finally {
            $lock->release();
        }
    }

    public function setReady(): void
    {
        $lock = $this->readyMu->acquireWrite();
        try {
            $this->ready = true;
            if ($this->readyChan !== null) {
                $this->readyChan->complete();
                $this->readyChan = null;
            }
        } finally {
            $lock->release();
        }
    }

    public function isReady(): bool
    {
        $lock = $this->readyMu->acquireRead();
        try {
            return $this->ready;
        } finally {
            $lock->release();
        }
    }

    //
    // Segment Management
    //
    public function addSegment(int $index, string $filename): void
    {
        $lock = $this->segmentsMu->acquireWrite();
        try {
            $this->segments[$index] = $filename;
        } finally {
            $lock->release();
        }
    }

    public function getSegment(int $index): ?string
    {
        $lock = $this->segmentsMu->acquireRead();
        try {
            return $this->segments[$index] ?? null;
        } finally {
            $lock->release();
        }
    }

    public function isSegmentTranscoded(int $index): bool
    {
        $lock = $this->segmentsMu->acquireRead();
        try {
            return isset($this->segments[$index]) && $this->segments[$index] !== '';
        } finally {
            $lock->release();
        }
    }

    public function clearSegments(): void
    {
        $lock = $this->segmentsMu->acquireWrite();
        try {
            foreach ($this->segments as $index => $filename) {
                $filePath = $this->config->getTranscodeDir() . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                unset($this->segments[$index]);
            }
        } finally {
            $lock->release();
        }
    }

    //
    // Segment Queue Management
    //
    public function enqueueSegments(int $offset, int $limit): void
    {
        $lock = $this->segmentQueueMu->acquireWrite();
        try {
            for ($i = $offset; $i < $offset + $limit; $i++) {
                $this->segmentQueue[$i] = new DeferredFuture();
            }
        } finally {
            $lock->release();
        }
    }

    public function dequeueSegment(int $index): void
    {
        $lock = $this->segmentQueueMu->acquireWrite();
        try {
            if (isset($this->segmentQueue[$index])) {
                $this->segmentQueue[$index]->complete();
                unset($this->segmentQueue[$index]);
            }
        } finally {
            $lock->release();
        }
    }

    public function waitForSegment(int $index): ?DeferredFuture
    {
        $lock = $this->segmentQueueMu->acquireRead();
        try {
            return $this->segmentQueue[$index] ?? null;
        } finally {
            $lock->release();
        }
    }

    //
    // ServeMedia Method
    //
    public function serveMedia(int $index)
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Manager is not ready.');
        }

        $segmentPath = $this->getSegment($index);
        if (!$segmentPath) {
            if (!$this->isSegmentTranscoded($index)) {
                $this->triggerSegmentTranscoding($index);
            }

            $deferredFuture = $this->waitForSegment($index);
            if ($deferredFuture === null) {
                throw new RuntimeException("Segment $index not found in the queue.");
            }

            try {
                $deferredFuture->getFuture()->await();
            } catch (CancelledException $e) {
                throw new RuntimeException("Segment $index transcoding was cancelled.", 0, $e);
            }

            $segmentPath = $this->getSegment($index);
            if (!$segmentPath) {
                throw new RuntimeException("Segment $index still not available after transcoding.");
            }
        }

        if (!File\exists($segmentPath)) {
            throw new RuntimeException("Media segment file not found: $segmentPath");
        }

        return File\read($segmentPath);
    }

    private function triggerSegmentTranscoding(int $index): void
    {
        $this->transcodeFromSegment($index);
    }

    private function transcodeFromSegment(int $index): void
    {
        $segmentsTotal = count($this->segments);

        if ($segmentsTotal <= $this->segmentBufferMax) {
            $offset = 0;
            $limit = $segmentsTotal;
        } else {
            $offset = max(0, $index - $this->segmentBufferMin);
            $limit = $index + $this->segmentBufferMax - $offset;
        }

        $this->transcodeSegments($offset, $limit);
    }

    private function transcodeSegments(int $offset, int $limit): void
    {
        $this->enqueueSegments($offset, $limit);

        $this->logger->info('Starting transcoding', ['offset' => $offset, 'limit' => $limit]);

        for ($i = $offset; $i < $offset + $limit; $i++) {
            $segmentName = $this->getSegmentName($i);
            File\write($this->config->getTranscodeDir() . DIRECTORY_SEPARATOR . $segmentName, 'Fake segment data');
            $this->addSegment($i, $segmentName);
            $this->dequeueSegment($i);
        }

        $this->logger->info('Transcoding segments completed.');
    }

    private function getSegmentName(int $index): string
    {
        return sprintf('%s-%05d.ts', $this->config->getSegmentPrefix(), $index);
    }
}