import type { SectionFactory } from '../types';

export const editSection: SectionFactory = ({ t, platform }) => {
  const submenu: any[] = [
    { role: 'undo' as const, label: t('menu.edit.undo') },
    { role: 'redo' as const, label: t('menu.edit.redo') },
    { type: 'separator' as const },
    { role: 'cut' as const, label: t('menu.edit.cut') },
    { role: 'copy' as const, label: t('menu.edit.copy') },
    { role: 'paste' as const, label: t('menu.edit.paste') },
    { role: 'selectAll' as const, label: t('menu.edit.selectAll') },
  ];

  if (platform === 'darwin') {
    submenu.push(
      { type: 'separator' as const },
      { label: t('menu.edit.speech'), submenu: [
          { role: 'startSpeaking' as const, label: t('menu.edit.startSpeaking') },
          { role: 'stopSpeaking' as const, label: t('menu.edit.stopSpeaking') },
        ]},
    );
  }

  return [
    { label: t('menu.edit._'), submenu },
  ];
};
