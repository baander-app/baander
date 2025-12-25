<?php

namespace App\Services;

use EliasHaeussler\SSE\Stream\EventStream;
use EliasHaeussler\SSE\Stream\SelfEmittingEventStream;
use Illuminate\Support\Collection;

class SseService
{
    /**
     * @var Collection<string, EventStream>
     */
    private Collection $emitters;

    public function __construct()
    {
        $this->emitters = collect();
    }

    public function addMember(string $token)
    {
        $emitter = SelfEmittingEventStream::create($token);

        $this->emitters->push($token, $emitter);

        return $emitter;
    }

    public function removeMember(string $token) {
       return $this->emitters->pull($token);
    }
}