<?php

namespace Baander\Transcoder\Sync;

use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use function Amp\delay;

class RWMutex
{
    private Mutex $mutex;
    private int $readerCount = 0;
    private bool $writerPresent = false;

    public function __construct()
    {
        // Use a LocalMutex for managing overall locking
        $this->mutex = new LocalMutex();
    }

    /**
     * Acquire a shared (read) lock.
     *
     * @return Lock A handle to the read lock.
     */
    public function acquireRead(): Lock
    {
        // Acquire internal mutex
        $lock = $this->mutex->acquire();

        // Perform state checks
        try {
            while ($this->writerPresent) {
                delay(0.01); // Wait 10ms until no writer is present
            }

            $this->readerCount++; // Increment reader count
        } finally {
            $lock->release(); // Release internal lock
        }

        // Return a lock that decreases the reader count on release
        return new Lock(function () {
            $this->decrementReaderCount();
        });
    }

    /**
     * Decrement reader count safely.
     */
    private function decrementReaderCount(): void
    {
        $lock = $this->mutex->acquire();

        try {
            if ($this->readerCount > 0) {
                $this->readerCount--; // Decrease reader count
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Acquire an exclusive (write) lock.
     *
     * @return Lock A handle to the write lock.
     */
    public function acquireWrite(): Lock
    {
        // Acquire internal mutex
        $lock = $this->mutex->acquire();

        // Perform state checks
        try {
            // Wait until no readers or writers are active
            while ($this->readerCount > 0 || $this->writerPresent) {
                delay(0.01); // Non-blocking sleep for 10ms
            }

            $this->writerPresent = true; // Mark writer active
        } finally {
            $lock->release(); // Release internal lock
        }

        // Return a lock that unsets the writer present flag on release
        return new Lock(function () {
            $this->unsetWriterFlag();
        });
    }

    /**
     * Unset the writer flag safely.
     */
    private function unsetWriterFlag(): void
    {
        $lock = $this->mutex->acquire();

        try {
            $this->writerPresent = false; // Writer is no longer present
        } finally {
            $lock->release();
        }
    }
}