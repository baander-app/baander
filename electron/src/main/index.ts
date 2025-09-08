import { app } from 'electron';
import { installOrUpdateCorsShim } from './security/cors-shim';
import { createMainWindow } from './windows/main-window';
import { createConfigWindow } from './windows/config-window';
import { registerIpc } from '../ipc';
import { setupApplicationMenu } from './menu';
import { createMenuTranslator } from './menu/i18n';
import { setupTray, destroyTray } from './menu/tray';
import { initMainProcessImpl, getServerUrlSync } from '../shared/config-store';
import { createRequire } from 'node:module';
import { enableCrossOriginIsolation } from './security/cross-origin-isolation-shim';
import * as fs from 'node:fs';
import * as path from 'node:path';


// Initialize the config store for the main process
initMainProcessImpl(app, fs, path);

const require = createRequire(import.meta.url);
require('v8-compile-cache');

const gotSingleInstanceLock = app.requestSingleInstanceLock();
if (!gotSingleInstanceLock) {
  app.quit();
} else {
  // If a second instance is launched, focus/restore existing window
  app.on('second-instance', (_event, _argv, _workingDirectory) => {
    // Decide which window should be focused based on current configuration
    const configured = getServerUrlSync();
    const win = configured ? (createMainWindow as any).get?.() : (createConfigWindow as any)?.get?.() || null;

    // If no window exists (rare), create the appropriate one
    const target = win ?? (configured ? createMainWindow() : createConfigWindow());
    enableCrossOriginIsolation(target);

    if (target) {
      if (target.isMinimized()) target.restore();
      if (!target.isVisible()) target.show();
      target.focus();
    }
  });
}

function getRendererOrigin() {
  return process.env.VITE_DEV_SERVER_URL ? process.env.VITE_DEV_SERVER_URL : 'file://';
}

app.whenReady().then(async () => {
  const rendererOrigin = getRendererOrigin();

  const t = createMenuTranslator();
  const ctx = {
    platform: process.platform,
    state: {
      isDev: !app.isPackaged,
      isPlaying: false,
      canPlay: true,
      canPause: true,
      canNext: false,
      canPrev: false,
      canSeek: false,
      isAuthed: false,
    },
    t,
  } as const;

  setupApplicationMenu(ctx);
  setupTray(ctx);

  installOrUpdateCorsShim(getServerUrlSync(), rendererOrigin);
  registerIpc({ rendererOrigin });

  const configured = getServerUrlSync();
  // Create the window first so we know which session it uses
  const win = configured ? createMainWindow() : createConfigWindow();
  win.webContents.openDevTools();
  win.show();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

app.on('before-quit', () => {
  destroyTray();
});
