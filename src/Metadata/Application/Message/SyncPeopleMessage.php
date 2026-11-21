<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

final readonly class SyncPeopleMessage
{
    public function __construct(public ?string $after = null) {}
}
