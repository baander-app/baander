<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\UserPreference\Domain\ValueObject\SidebarItemType;

final readonly class SidebarItem
{
    public function __construct(
        public string $id,
        public SidebarItemType $type,
        public string $label,
        public string $icon,
        public array $config = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->label,
            'icon' => $this->icon,
            'config' => $this->config,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = SidebarItemType::tryFrom($data['type'] ?? '');

        return new self(
            id: (string) ($data['id'] ?? ''),
            type: $type ?? SidebarItemType::PageLink,
            label: (string) ($data['label'] ?? ''),
            icon: (string) ($data['icon'] ?? ''),
            config: (array) ($data['config'] ?? []),
        );
    }
}
