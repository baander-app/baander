<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\SidebarConfig;
use App\UserPreference\Domain\Model\SidebarConfigState;
use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Domain\ValueObject\SidebarItemType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SidebarConfigTest extends TestCase
{
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v4();
    }

    public function testCreateSetsIdentityAndDefaults(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $config = SidebarConfig::create($this->userId, 'music');

        $this->assertFalse($config->getId()->equals($this->userId));
        $this->assertTrue($config->getUserId()->equals($this->userId));
        $this->assertSame('music', $config->getMediaType());
        $this->assertSame([], $config->getItems());
        $this->assertGreaterThanOrEqual($before, $config->getState()->createdAt);
        $this->assertGreaterThanOrEqual($before, $config->getState()->updatedAt);
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = SidebarConfig::create($this->userId, 'music');
        $b = SidebarConfig::create($this->userId, 'music');

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testCreateWithItems(): void
    {
        $items = [
            new SidebarItem('home', SidebarItemType::PageLink, 'Home', 'home'),
            new SidebarItem('recent', SidebarItemType::SmartFilter, 'Recent', 'clock'),
        ];

        $config = SidebarConfig::create($this->userId, 'music', $items);

        $this->assertSame($items, $config->getItems());
        $this->assertCount(2, $config->getItems());
    }

    public function testCreateThrowsWhenItemIsNotSidebarItem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each sidebar item must be a SidebarItem instance.');

        /** @phpstan-ignore-next-line intentionally passing invalid type */
        SidebarConfig::create($this->userId, 'music', ['not-an-item']);
    }

    public function testCreateThrowsWhenLabelIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sidebar item label cannot be empty.');

        SidebarConfig::create(
            $this->userId,
            'music',
            [new SidebarItem('home', SidebarItemType::PageLink, '', 'home')],
        );
    }

    public function testCreateThrowsWhenLabelIsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sidebar item label cannot be empty.');

        SidebarConfig::create(
            $this->userId,
            'music',
            [new SidebarItem('home', SidebarItemType::PageLink, "  \t", 'home')],
        );
    }

    public function testUpdateItemsReplacesAndRefreshesTimestamp(): void
    {
        // Reconstitute with a known historical timestamp for a deterministic assertion.
        $config = SidebarConfig::reconstitute(new SidebarConfigState(
            id: Uuid::v4(),
            userId: $this->userId,
            mediaType: 'music',
            items: [],
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ));
        $originalUpdatedAt = $config->getState()->updatedAt;

        $items = [new SidebarItem('queue', SidebarItemType::PanelAction, 'Queue', 'list')];
        $config->updateItems($items);

        $this->assertSame($items, $config->getItems());
        $this->assertGreaterThan($originalUpdatedAt, $config->getState()->updatedAt);
    }

    public function testUpdateItemsThrowsWhenItemIsNotSidebarItem(): void
    {
        $config = SidebarConfig::create($this->userId, 'music');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each sidebar item must be a SidebarItem instance.');

        /** @phpstan-ignore-next-line intentionally passing invalid type */
        $config->updateItems(['nope']);
    }

    public function testUpdateItemsThrowsWhenLabelEmpty(): void
    {
        $config = SidebarConfig::create($this->userId, 'music');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sidebar item label cannot be empty.');

        $config->updateItems([new SidebarItem('home', SidebarItemType::PageLink, '   ', 'home')]);
    }

    public function testReconstitutePreservesStateExactly(): void
    {
        $items = [new SidebarItem('home', SidebarItemType::PageLink, 'Home', 'home')];
        $state = new SidebarConfigState(
            id: Uuid::v4(),
            userId: $this->userId,
            mediaType: 'video',
            items: $items,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );

        $config = SidebarConfig::reconstitute($state);

        $this->assertSame($state, $config->getState());
        $this->assertTrue($config->getId()->equals($state->id));
        $this->assertTrue($config->getUserId()->equals($state->userId));
        $this->assertSame('video', $config->getMediaType());
        $this->assertSame($items, $config->getItems());
    }
}
