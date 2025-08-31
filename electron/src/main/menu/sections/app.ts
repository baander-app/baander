import type { SectionFactory } from '../types';
import { MenuActionId } from '../ids';
import { MenuItemConstructorOptions } from 'electron';

export const appSection: SectionFactory = ({ t, platform }) => {
  const isMac = platform === 'darwin';
  if (!isMac) return [];

  const items: MenuItemConstructorOptions[] = [
    // Valid Electron role on macOS
    { role: 'about', label: t('menu.app.about') },
    { type: 'separator' },

    // 'preferences' is NOT a valid Electron role. Use a normal item with an id.
    { id: MenuActionId.AppPreferences, label: t('menu.app.preferences') },

    { type: 'separator' },

    // Valid macOS-only role
    { role: 'services', submenu: [] },

    { type: 'separator' },

    // Valid macOS roles (you can override labels)
    { role: 'hide', label: t('menu.app.hide') },
    { role: 'hideOthers', label: t('menu.app.hideOthers') },
    { role: 'unhide', label: t('menu.app.unhide') },

    { type: 'separator' },

    // Valid role
    { role: 'quit', label: t('menu.app.quit') },
  ];

  return items;
};
