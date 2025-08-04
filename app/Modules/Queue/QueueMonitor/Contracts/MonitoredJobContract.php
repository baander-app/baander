<?php

namespace App\Modules\Queue\QueueMonitor\Contracts;

interface MonitoredJobContract
{
    public function queueProgress(int $progress): void;

    public function queueProgressChunk(int $collectionCount, int $perChunk): void;

    /**
     * @param array $data
     * @param bool $merge
     *
     * @return void
     */
    public function queueData(array $data, bool $merge = false): void;

    public static function keepMonitorOnSuccess(): bool;

    public function progressCooldown(): int;

    /**
     * @return array|null
     */
    public function initialMonitorData(): ?array;
}