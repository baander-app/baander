<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

interface QoLJobIdentityInterface
{
    /** @param list<string> $internalIds
     *  @return array<string, string> internal ID => public ID
     */
    public function publicIds(array $internalIds): array;
}
