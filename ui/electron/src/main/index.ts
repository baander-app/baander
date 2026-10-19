import { app, screen, globalShortcut } from 'electron';
import { hardenSecurity } from './security/harden';
import { installOrUpdateCorsShim } from './security/cors-shim';
import { createMainWindow, getMainWindow } from './windows/main-window';
import { createConfigWindow } from './windows/config-window';
import { registerIpc } from '../ipc';
import { setupApplicationMenu } from './menu';
import { createMenuTranslator } from './menu/i18n';
import { destroyTray, setupTray } from './menu/tray';
import { getServerUrlSync, initMainProcessImpl } from '../shared/config-store';
import { createRequire } from 'node:module';
import { DeepLinkService } from './services/deep-link.service';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { setCliFlags } from './flags';
import { registerWasmProtocol } from './protocols/resource-protocol';

if (process.platform === 'win32') {
  process.env.FONTCONFIG_FILE = path.join(import.meta.url, 'fonts.conf');
}

setCliFlags();

// Initialize the config store for the main process
// @ts-ignore
initMainProcessImpl(app, fs, path);

const require = createRequire(import.meta.url);

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
  hardenSecurity();
  // Register custom protocol for serving WASM files (before creating windows)
  registerWasmProtocol();

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
    },
  });

  // Clean up global shortcuts on quit
  app.on('will-quit', () => {
    globalShortcut.unregisterAll();
  });

  // Register global media key shortcuts
  const registerMediaKeys = () => {
    for (const [key, channel] of [
      ['MediaPlayPause', 'baander:playback:toggle'],
      ['MediaNextTrack', 'baander:playback:next'],
      ['MediaPreviousTrack', 'baander:playback:previous'],
    ]) {
      globalShortcut.register(key, () => {
        const window = getMainWindow();
        if (window && !window.isDestroyed() && !window.webContents.isDestroyed()) {
          window.webContents.send(channel);
        }
      });
    }
  };

  const configured = getServerUrlSync();
  // Create the window first so we know which session it uses
  const win = configured ? createMainWindow() : createConfigWindow();

  registerMediaKeys();

  win.webContents.openDevTools();
  win.show();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

app.on('before-quit', () => {
  destroyTray();
});
