import type { SectionFactory } from '../types';
import type { MenuItemConstructorOptions } from 'electron';
import { MenuActionId } from '../ids';

export const fileSection: SectionFactory = ({ t, platform }) => {
  // Explicitly type to avoid narrow union inference from first items
  const base: MenuItemConstructorOptions[] = [
    { id: MenuActionId.FileOpen, label: t('menu.file.open') },
    { id: MenuActionId.FileClose, label: t('menu.file.close'), role: 'close' as const },
  ];

  if (platform !== 'darwin') {
    base.push(
      { type: 'separator' },
      { role: 'quit', label: t('menu.file.quit') }
    );
  }

  return [
    { label: t('menu.file._'), submenu: base },
  ];
};
