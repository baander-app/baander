<?php

namespace App\Modules\Transcoder;

class PooledConnection
{
    private int $lastUsed;

    public function __construct(
        private readonly int          $id,
        private readonly SocketClient $client,
        int                           $lastUsed,
    )
    {
        $this->lastUsed = $lastUsed;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getClient(): SocketClient
    {
        return $this->client;
    }

    public function getLastUsed(): int
    {
        return $this->lastUsed;
    }

    public function setLastUsed(int $time): void
    {
        $this->lastUsed = $time;
    }
}