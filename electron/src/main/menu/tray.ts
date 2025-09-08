import { BrowserWindow, Menu, Tray, nativeImage } from 'electron';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { existsSync } from 'node:fs';
import { MenuActionId } from './ids';
import type { AppMenuContext } from './types';
import { dispatchMenuAction } from './index';

const __dirname = dirname(fileURLToPath(import.meta.url));
let tray: Tray | null = null;

function resolveTrayIcon() {
  // Try packaged locations first, then dev
  const candidates = [
    // Common packaged resource locations
    join(process.resourcesPath, 'icon.png'),
    join(process.resourcesPath, 'icon.ico'),
    // Dev/build resources (relative to compiled main)
    join(__dirname, '../../build/app-icons/icon.png'),
    join(__dirname, '../../build/app-icons/icon.ico'),
  ];
  for (const p of candidates) {
    if (existsSync(p)) return p;
  }
  return undefined;
}

function getMainWindow(): BrowserWindow | null {
  const wins = BrowserWindow.getAllWindows().filter(w => !w.isDestroyed());
  if (wins.length === 0) return null;
  // Prefer focused, else first
  return BrowserWindow.getFocusedWindow() ?? wins[0];
}

export function setupTray(ctx: AppMenuContext) {
  if (tray) return tray;

  const iconPath = resolveTrayIcon();
  const img = iconPath ? nativeImage.createFromPath(iconPath) : nativeImage.createEmpty();

  tray = new Tray(process.platform === 'darwin' ? img.resize({ width: 18, height: 18 }) : img);
  tray.setToolTip('Bånder');

  // Left-click: show or focus main window
  tray.on('click', () => {
    const win = getMainWindow();
    if (!win) return;
    if (win.isMinimized()) win.restore();
    if (!win.isVisible()) win.show();
    win.focus();
  });

  // Build context menu using same action IDs
  const template: Electron.MenuItemConstructorOptions[] = [
    { label: 'Show/Hide', click: () => {
        const win = getMainWindow();
        if (!win) return;
        if (win.isVisible()) win.hide(); else { win.show(); win.focus(); }
      }},
    { type: 'separator' },

    { label: 'Play/Pause', click: () => dispatchMenuAction(MenuActionId.PlaybackToggle) },
    { label: 'Next', click: () => dispatchMenuAction(MenuActionId.PlaybackNext) },
    { label: 'Previous', click: () => dispatchMenuAction(MenuActionId.PlaybackPrev) },

    { type: 'separator' },
    { label: 'Preferences', click: () => dispatchMenuAction(MenuActionId.AppPreferences) },

    ...(ctx.state.isDev ? ([
      { type: 'separator' as const },
      { label: 'Toggle DevTools', click: () => dispatchMenuAction(MenuActionId.ViewToggleDevTools) },
      { label: 'Reload', click: () => dispatchMenuAction(MenuActionId.ViewReload) },
    ] as Electron.MenuItemConstructorOptions[]) : []),

  { type: 'separator' },
  { label: 'Quit', role: 'quit' },
];

  const menu = Menu.buildFromTemplate(template);
  tray.setContextMenu(menu);

  return tray;
}

export function destroyTray() {
  if (tray) {
    tray.destroy();
    tray = null;
  }
}
