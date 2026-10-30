<?php

declare(strict_types=1);

namespace Baander\Tooling\Docker;

use Baander\Tooling\Configuration\ImageSelection;

final readonly class ResolvedImage
{
    public function __construct(public ImageSelection $selection, public string $id, public string $daemonId)
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $id) !== 1 || $daemonId === '') {
            throw new \RuntimeException('Docker did not report an immutable image identity.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [...$this->selection->toArray(), 'id' => $this->id, 'daemonId' => $this->daemonId];
    }
}
