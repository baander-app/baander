<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

final class SwooleWorkerEventBuffer
{
    /** @var array<int, array{type: string, workerId: int, timestamp: float}> */
    private array $events = [];

    private int $position = 0;

    public function __construct(private readonly int $size = 100) {}

    public function push(string $type, int $workerId): void
    {
        $this->events[$this->position] = [
            'type' => $type,
            'workerId' => $workerId,
            'timestamp' => microtime(true),
        ];
        $this->position = ($this->position + 1) % $this->size;
    }

    /**
     * @return array<int, array{type: string, workerId: int, timestamp: float}>
     */
    public function getAll(): array
    {
        $sorted = [];
        for ($i = 0; $i < $this->size; $i++) {
            $idx = ($this->position + $i) % $this->size;
            if (isset($this->events[$idx])) {
                $sorted[] = $this->events[$idx];
            }
        }

        return $sorted;
    }
}
