<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class ImageCatalog
{
    /** @param array<string,ImageSelection> $roles */
    public function __construct(private array $roles) {}

    public function role(string $role): ImageSelection
    {
        return $this->roles[$role] ?? throw new \InvalidArgumentException('Unknown image role: ' . $role);
    }

    /** @return array<string,array{reference:string,capabilities:list<string>,playwrightVersion:?string}> */
    public function toArray(): array
    {
        return array_map(static fn(ImageSelection $image): array => $image->toArray(), $this->roles);
    }
}
