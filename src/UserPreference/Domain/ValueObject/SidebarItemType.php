<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\ValueObject;

enum SidebarItemType: string
{
    case PageLink = 'page_link';
    case SmartFilter = 'smart_filter';
    case PanelAction = 'panel_action';
}
