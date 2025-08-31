import { app } from 'electron';
import { installOrUpdateCorsShim } from './security/cors-shim';
import { getServerUrl } from '../shared/config-store';
import { createMainWindow } from './windows/main-window';
import { createConfigWindow } from './windows/config-window';
import { registerIpc } from '../ipc';
import { setupApplicationMenu } from './menu';
import { createMenuTranslator } from './menu/i18n';


// Provide CommonJS require in ESM context for CJS-only consumers (e.g., v8-compile-cache)
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
require('v8-compile-cache');

function getRendererOrigin() {
  return process.env.VITE_DEV_SERVER_URL ? process.env.VITE_DEV_SERVER_URL : 'file://';
}

app.whenReady().then(async () => {
  const rendererOrigin = getRendererOrigin();

  const t = createMenuTranslator();
  setupApplicationMenu({
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
  });

  installOrUpdateCorsShim(getServerUrl(), rendererOrigin);
  registerIpc({ rendererOrigin });

  const configured = getServerUrl();
  // Create the window first so we know which session it uses
  const win = configured ? createMainWindow() : createConfigWindow();
  win.show();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
