import type { SectionFactory } from '../types';
import { accel } from '../accelerators';
import { MenuActionId } from '../ids';

export const viewSection: SectionFactory = ({ t, platform, state }) => {
  const isMac = platform === 'darwin';
  return [
    {
      label: t('menu.view._'),
      submenu: [
        { id: MenuActionId.ViewReload, label: t('menu.view.reload'), accelerator: accel('reload', isMac) },
        { type: 'separator' as const },
        { id: MenuActionId.ViewToggleFullScreen, label: t('menu.view.toggleFullScreen'), accelerator: accel('toggleFullScreen', isMac), role: 'togglefullscreen' as const },
        { type: 'separator' as const },
        { role: 'zoomIn' as const, label: t('menu.view.zoomIn') },
        { role: 'zoomOut' as const, label: t('menu.view.zoomOut') },
        { role: 'resetZoom' as const, label: t('menu.view.resetZoom') },
      ],
    },
  ];
};
