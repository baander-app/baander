<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class SidebarConfig
{
    /** @var string[] */
    private const ALLOWED_TYPES = ['page_link', 'smart_filter', 'panel_action'];

    private function __construct(
        private SidebarConfigState $state,
    ) {
    }

    /**
     * Create a new sidebar config for a user and media type.
     *
     * @param SidebarItem[] $items
     */
    public static function create(Uuid $userId, string $mediaType, array $items = []): self
    {
        self::validateItems($items);

        $now = new DateTimeImmutable();

        return new self(new SidebarConfigState(
            id: Uuid::generate(),
            userId: $userId,
            mediaType: $mediaType,
            items: $items,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(SidebarConfigState $state): self
    {
        return new self($state);
    }

    /**
     * Replace all sidebar items.
     *
     * @param SidebarItem[] $items
     */
    public function updateItems(array $items): void
    {
        self::validateItems($items);

        $this->state->items = $items;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * @return SidebarItem[]
     */
    public function getItems(): array
    {
        return $this->state->items;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getMediaType(): string
    {
        return $this->state->mediaType;
    }

    public function getState(): SidebarConfigState
    {
        return $this->state;
    }

    /**
     * @param SidebarItem[] $items
     */
    private static function validateItems(array $items): void
    {
        foreach ($items as $item) {
            if (!($item instanceof SidebarItem)) {
                throw new InvalidArgumentException('Each sidebar item must be a SidebarItem instance.');
            }

            if (trim($item->label) === '') {
                throw new InvalidArgumentException('Sidebar item label cannot be empty.');
            }

            if (!in_array($item->type->value, self::ALLOWED_TYPES, true)) {
                throw new InvalidArgumentException(
                    sprintf('Invalid sidebar item type "%s".', $item->type->value),
                );
            }
        }
    }
}
