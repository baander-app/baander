<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

interface LiveRevisionStore
{
    /**
     * Missing scopes have not yet been projected. Revisions are decimal strings.
     * @param list<LiveScope> $scopes
     * @return array<string, string> Scope key to revision.
     */
    public function revisions(array $scopes): array;

    public function health(): LivePipelineHealth;
}
