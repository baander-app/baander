<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Domain\ValueObject;

use App\UserPreference\Domain\ValueObject\SidebarItemType;
use PHPUnit\Framework\TestCase;
use ValueError;

final class SidebarItemTypeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, SidebarItemType::cases());
    }

    public function testPageLinkValue(): void
    {
        $this->assertSame('page_link', SidebarItemType::PageLink->value);
    }

    public function testSmartFilterValue(): void
    {
        $this->assertSame('smart_filter', SidebarItemType::SmartFilter->value);
    }

    public function testPanelActionValue(): void
    {
        $this->assertSame('panel_action', SidebarItemType::PanelAction->value);
    }

    public function testFromValidStringReturnsCase(): void
    {
        $this->assertSame(SidebarItemType::PageLink, SidebarItemType::from('page_link'));
        $this->assertSame(SidebarItemType::SmartFilter, SidebarItemType::from('smart_filter'));
        $this->assertSame(SidebarItemType::PanelAction, SidebarItemType::from('panel_action'));
    }

    public function testTryFromValidStringReturnsCase(): void
    {
        $this->assertSame(SidebarItemType::SmartFilter, SidebarItemType::tryFrom('smart_filter'));
    }

    public function testTryFromInvalidStringReturnsNull(): void
    {
        // PHPStan statically narrows tryFrom() of a non-matching literal to null;
        // the assertion still validates the runtime behaviour.
        /** @phpstan-ignore-next-line */
        $this->assertNull(SidebarItemType::tryFrom('unknown'));
        /** @phpstan-ignore-next-line */
        $this->assertNull(SidebarItemType::tryFrom(''));
    }

    public function testFromInvalidStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);

        SidebarItemType::from('nope');
    }
}
