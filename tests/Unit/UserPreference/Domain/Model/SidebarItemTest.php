<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Domain\Model;

use App\UserPreference\Domain\Model\SidebarItem;
use App\UserPreference\Domain\ValueObject\SidebarItemType;
use PHPUnit\Framework\TestCase;

final class SidebarItemTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $item = new SidebarItem(
            id: 'home',
            type: SidebarItemType::PageLink,
            label: 'Home',
            icon: 'home',
            config: ['route' => '/home'],
        );

        $this->assertSame('home', $item->id);
        $this->assertSame(SidebarItemType::PageLink, $item->type);
        $this->assertSame('Home', $item->label);
        $this->assertSame('home', $item->icon);
        $this->assertSame(['route' => '/home'], $item->config);
    }

    public function testConfigDefaultsToEmptyArray(): void
    {
        $item = new SidebarItem(
            id: 'home',
            type: SidebarItemType::PageLink,
            label: 'Home',
            icon: 'home',
        );

        $this->assertSame([], $item->config);
    }

    public function testToArrayReturnsFullShape(): void
    {
        $item = new SidebarItem(
            id: 'recent',
            type: SidebarItemType::SmartFilter,
            label: 'Recently Played',
            icon: 'clock',
            config: ['filter' => 'recent'],
        );

        $this->assertSame(
            [
                'id' => 'recent',
                'type' => 'smart_filter',
                'label' => 'Recently Played',
                'icon' => 'clock',
                'config' => ['filter' => 'recent'],
            ],
            $item->toArray(),
        );
    }

    public function testFromArrayCreatesItemFromFullData(): void
    {
        $item = SidebarItem::fromArray([
            'id' => 'action-1',
            'type' => 'panel_action',
            'label' => 'Queue',
            'icon' => 'list',
            'config' => ['open' => true],
        ]);

        $this->assertSame('action-1', $item->id);
        $this->assertSame(SidebarItemType::PanelAction, $item->type);
        $this->assertSame('Queue', $item->label);
        $this->assertSame('list', $item->icon);
        $this->assertSame(['open' => true], $item->config);
    }

    public function testFromArrayRoundTripsThroughToArray(): void
    {
        $original = new SidebarItem(
            id: 'recent',
            type: SidebarItemType::SmartFilter,
            label: 'Recently Played',
            icon: 'clock',
            config: ['filter' => 'recent'],
        );

        $restored = SidebarItem::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
        $this->assertSame($original->type, $restored->type);
    }

    public function testFromArrayDefaultsToPageLinkForUnknownType(): void
    {
        $item = SidebarItem::fromArray([
            'id' => 'home',
            'type' => 'totally_unknown',
            'label' => 'Home',
            'icon' => 'home',
        ]);

        $this->assertSame(SidebarItemType::PageLink, $item->type);
    }

    public function testFromArrayDefaultsToPageLinkWhenTypeMissing(): void
    {
        $item = SidebarItem::fromArray([
            'id' => 'home',
            'label' => 'Home',
            'icon' => 'home',
        ]);

        $this->assertSame(SidebarItemType::PageLink, $item->type);
    }

    public function testFromArrayDefaultsMissingScalarKeys(): void
    {
        $item = SidebarItem::fromArray([]);

        $this->assertSame('', $item->id);
        $this->assertSame('', $item->label);
        $this->assertSame('', $item->icon);
        $this->assertSame([], $item->config);
        $this->assertSame(SidebarItemType::PageLink, $item->type);
    }
}
