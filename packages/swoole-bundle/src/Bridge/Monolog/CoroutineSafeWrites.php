<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Monolog;

use Assert\Assertion;
use Closure;
use Monolog\LogRecord;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Concurrency\SerialQueue;

trait CoroutineSafeWrites
{
    private const int WRITE_QUEUE_CAPACITY = 4096;

    private ?SerialQueue $writeQueue = null;
    private ?Swoole $swoole = null;

    public function setSwoole(Swoole $swoole): void
    {
        $this->swoole = $swoole;
    }

    public function close(): void
    {
        $this->inWriteQueue(function (): void {
            parent::close();
        });
    }

    public function reset(): void
    {
        $this->inWriteQueue(function (): void {
            parent::reset();
        });
    }

    protected function write(LogRecord $record): void
    {
        Assertion::string($record->formatted);
        $this->writeQueue()->submit($record->with(context: [], extra: [], formatted: $record->formatted));
    }

    /** @param Closure(): void $task */
    private function inWriteQueue(Closure $task): void
    {
        if ($this->writeQueue === null) {
            $task();

            return;
        }
        $this->writeQueue->runInConsumer($task);
    }

    private function writeQueue(): SerialQueue
    {
        return $this->writeQueue ??= new SerialQueue(
            $this->swoole ?? new Swoole(),
            function (mixed $record): void {
                Assertion::isInstanceOf($record, LogRecord::class);
                parent::write($record);
            },
            self::WRITE_QUEUE_CAPACITY,
            function (): void {
                parent::close();
            },
        );
    }
}
