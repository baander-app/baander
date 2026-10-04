<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ReadModel;

use App\Shared\Domain\Model\Uuid;

final readonly class GenreReadView
{
    public function __construct(
        private Uuid $id,
        private string $name,
        private string $slug,
        private ?Uuid $parent,
        private ?string $mbid,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getParent(): ?Uuid
    {
        return $this->parent;
    }

    public function getMbid(): ?string
    {
        return $this->mbid;
    }
}
