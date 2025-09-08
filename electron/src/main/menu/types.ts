import type { MenuItemConstructorOptions } from 'electron';

export type Platform = 'darwin' | 'win32' | 'linux' | 'unknown';

export type AppStateForMenu = {
  isDev: boolean;
  isPlaying: boolean;
  canPlay: boolean;
  canPause: boolean;
  canNext: boolean;
  canPrev: boolean;
  canSeek: boolean;
  isAuthed: boolean;
};

export type AppMenuContext = {
  platform: NodeJS.Platform;
  t: (key: string) => string; // simple i18n hook, replace later if needed
  state: AppStateForMenu;
};

export type SectionFactory = (ctx: AppMenuContext) => MenuItemConstructorOptions[];
