<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Answer to one server control operation.
 *
 * A fan-out operation has one entry per HTTP worker, in `results` when the worker
 * answered or in `errors` when its handler failed; workers that did not reply in
 * time are listed in `missingWorkers`. An operation whose answer is already
 * server-wide has a single entry, keyed by the worker that served it.
 */
final readonly class ServerControlResult
{
    /**
     * @param array<int, mixed> $results worker ID => the operation's JSON-compatible answer
     * @param array<int, string> $errors worker ID => why the operation failed there
     * @param list<int> $missingWorkers workers that did not reply in time
     */
    public function __construct(
        public array $results,
        public array $errors = [],
        public array $missingWorkers = [],
    ) {
    }

    /** False when any worker failed or did not reply: a write is then a partial failure, never a success. */
    public function isComplete(): bool
    {
        return $this->errors === [] && $this->missingWorkers === [];
    }
}
