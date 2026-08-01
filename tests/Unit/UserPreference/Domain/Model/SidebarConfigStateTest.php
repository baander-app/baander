<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\SidebarConfigState;
use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Domain\ValueObject\SidebarItemType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SidebarConfigStateTest extends TestCase
{
    public function testConstructorAssignsAllPublicProperties(): void
    {
        $id = Uuid::v4();
        $userId = Uuid::v4();
        $items = [new SidebarItem('home', SidebarItemType::PageLink, 'Home', 'home')];
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $updatedAt = new DateTimeImmutable('2026-06-13T00:00:00+00:00');

        $state = new SidebarConfigState(
            id: $id,
            userId: $userId,
            mediaType: 'music',
            items: $items,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $this->assertTrue($state->id->equals($id));
        $this->assertTrue($state->userId->equals($userId));
        $this->assertSame('music', $state->mediaType);
        $this->assertSame($items, $state->items);
        $this->assertSame($createdAt, $state->createdAt);
        $this->assertSame($updatedAt, $state->updatedAt);
    }

    public function testItemsAcceptsEmptyArray(): void
    {
        $state = $this->buildState([]);

        $this->assertSame([], $state->items);
    }

    public function testPropertiesAreMutable(): void
    {
        $state = $this->buildState([]);

        $newItems = [new SidebarItem('queue', SidebarItemType::PanelAction, 'Queue', 'list')];
        $newUpdatedAt = new DateTimeImmutable('2026-06-13T12:00:00+00:00');

        $state->items = $newItems;
        $state->updatedAt = $newUpdatedAt;

        $this->assertSame($newItems, $state->items);
        $this->assertSame($newUpdatedAt, $state->updatedAt);
    }

    /**
     * @param list<SidebarItem> $items
     */
    private function buildState(array $items): SidebarConfigState
    {
        return new SidebarConfigState(
            id: Uuid::v4(),
            userId: Uuid::v4(),
            mediaType: 'music',
            items: $items,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-13T00:00:00+00:00'),
        );
    }
}
