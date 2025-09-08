import type { SectionFactory } from '../types';
import { accel } from '../accelerators';
import { MenuActionId } from '../ids';
import { app } from 'electron';

export const developerSection: SectionFactory = ({ t, platform }) => {
  if(app.isPackaged) return (
    []
  )

  const isMac = platform === 'darwin';
  return [
    {
      label: t('menu.developer._'),
      submenu: [
        { id: MenuActionId.ViewReload, label: t('menu.view.reload'), accelerator: accel('reload', isMac) },
        { id: MenuActionId.ViewToggleDevTools, label: t('menu.view.toggleDevTools'), accelerator: accel('toggleDevTools', isMac) },
        { type: 'separator' as const },
        { id: MenuActionId.DevOpenUserData, label: t('menu.developer.openUserData') },
        { id: MenuActionId.DevOpenCache, label: t('menu.developer.openCache') },
        { id: MenuActionId.DevOpenLogs, label: t('menu.developer.openLogs') },
        { type: 'separator' as const },
        { id: MenuActionId.DevClearStore, label: t('menu.developer.clearStore') },
        { id: MenuActionId.DevShowConfigWindow, label: t('menu.developer.showConfigWindow') },
        { id: MenuActionId.DevResetServerUrl, label: t('menu.developer.resetServerUrl') },
        { type: 'separator' as const },
      ],
    },
  ];
};
