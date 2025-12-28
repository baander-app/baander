import { app, screen } from 'electron';
import { installOrUpdateCorsShim } from './security/cors-shim';
import { createMainWindow } from './windows/main-window';
import { createConfigWindow } from './windows/config-window';
import { registerIpc } from '../ipc';
import { setupApplicationMenu } from './menu';
import { createMenuTranslator } from './menu/i18n';
import { setupTray, destroyTray } from './menu/tray';
import { initMainProcessImpl, getServerUrlSync } from '../shared/config-store';
import { createRequire } from 'node:module';
import { DeepLinkService } from './services/deep-link.service';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { setCliFlags } from './flags';

if(process.platform === 'win32') {
  process.env.FONTCONFIG_FILE = path.join(import.meta.url, 'fonts.conf');
}

setCliFlags();

// Initialize the config store for the main process
// @ts-ignore
initMainProcessImpl(app, fs, path);

const require = createRequire(import.meta.url);
require('v8-compile-cache');

// Initialize deep link service
const deepLinkService = new DeepLinkService(getServerUrlSync);
deepLinkService.initializeProtocol();

const gotSingleInstanceLock = app.requestSingleInstanceLock();
if (!gotSingleInstanceLock) {
  app.quit();
} else {
  // Setup deep link event listeners
  deepLinkService.setupEventListeners();
}

function getRendererOrigin() {
  return process.env.VITE_DEV_SERVER_URL ? process.env.VITE_DEV_SERVER_URL : 'file://';
}

app.whenReady().then(async () => {
  const primary = screen.getPrimaryDisplay();
  if (primary.scaleFactor !== 1) {
    app.commandLine.appendSwitch('high-dpi-support', '1');
    app.commandLine.appendSwitch('force-device-scale-factor', '1');
  }

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

  // Register IPC with expanded context
  registerIpc({
    rendererOrigin,
    getServerUrlSync,
    deepLinkService: {
      getPendingUrl: () => deepLinkService.getPendingUrl(),
      clearPendingUrl: () => deepLinkService.clearPendingUrl(),
    }
  });

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
