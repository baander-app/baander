import type { SectionFactory } from '../types';

export const windowSection: SectionFactory = ({ t, platform }) => {
  if (platform === 'darwin') {
    return [
      {
        label: t('menu.window._'),
        submenu: [
          { role: 'minimize' as const, label: t('menu.window.minimize') },
          { role: 'zoom' as const, label: t('menu.window.zoom') },
          { type: 'separator' as const },
          { role: 'front' as const, label: t('menu.window.bringAllToFront') },
        ],
      },
    ];
  }
  return [
    {
      label: t('menu.window._'),
      submenu: [
        { role: 'minimize' as const, label: t('menu.window.minimize') },
        { role: 'close' as const, label: t('menu.window.close') },
      ],
    },
  ];
};
