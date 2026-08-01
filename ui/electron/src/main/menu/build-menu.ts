import { Menu, type MenuItemConstructorOptions } from 'electron';
import type { AppMenuContext } from './types';
import { appSection } from './sections/app';
import { fileSection } from './sections/file';
import { editSection } from './sections/edit';
import { viewSection } from './sections/view';
import { playbackSection } from './sections/playback';
import { windowSection } from './sections/window';
import { helpSection } from './sections/help';
import { applyMacConventions } from './platform/mac';
import { applyWinLinuxConventions } from './platform/winLinux';
import { developerSection } from './sections/developer';

export function buildMenuTemplate(ctx: AppMenuContext): MenuItemConstructorOptions[] {
  const isMac = ctx.platform === 'darwin';

  const sections: MenuItemConstructorOptions[] = [];

  if (isMac) {
    // App menu (macOS-specific top-level)
    sections.push(...appSection(ctx));
  }

  // Common top-level order
  sections.push(
    ...fileSection(ctx),
    ...editSection(ctx),
    ...viewSection(ctx),
    ...playbackSection(ctx),
    ...windowSection(ctx),
    ...helpSection(ctx),
  );

  if (ctx.state.isDev) {
    sections.push(...developerSection(ctx));
  }


  return isMac ? applyMacConventions(sections) : applyWinLinuxConventions(sections);
}

export function buildMenu(ctx: AppMenuContext): Menu {
  const template = buildMenuTemplate(ctx);
  return Menu.buildFromTemplate(template);
}
