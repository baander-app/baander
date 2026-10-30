<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LiveScope
{
    public function __construct(public LiveFamily $family, public LiveScopeKind $kind, public string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A live scope needs an identifier.');
        }
    }

    public function key(): string
    {
        return $this->family->value . ':' . $this->kind->value . ':' . $this->id;
    }
}
