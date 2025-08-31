import { BrowserWindow, app, dialog } from 'electron';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

let configWindow: BrowserWindow | null = null;

// __dirname for ESM bundles
const __dirname = dirname(fileURLToPath(import.meta.url));

function attachLoadGuards(win: BrowserWindow, label: string) {
  // Crash the app if the page fails to load, but first show an alert
  win.webContents.on('did-fail-load', (_e, errorCode, errorDescription, validatedURL) => {
    console.error(`[fatal] ${label} failed to load`, { errorCode, errorDescription, validatedURL });
    try {
      dialog.showMessageBoxSync(win, {
        type: 'error',
        title: `${label} failed to load`,
        message: `${label} failed to load`,
        detail: `Error ${errorCode}: ${errorDescription}\nURL: ${validatedURL || 'n/a'}`,
      });
    } catch {}
    app.exit(1);
  });

  // Crash the app if it doesn't finish loading in time, but first show an alert
  const watchdog = setTimeout(() => {
    console.error(`[fatal] ${label} did not finish loading in time`);
    try {
      dialog.showMessageBoxSync(win, {
        type: 'error',
        title: `${label} timeout`,
        message: `${label} did not finish loading in time`,
        detail: 'Please reinstall or contact support if this persists.',
      });
    } catch {}
    app.exit(1);
  }, 15000);

  win.webContents.once('did-finish-load', () => clearTimeout(watchdog));
}

export function createConfigWindow() {
  if (configWindow && !configWindow.isDestroyed()) return configWindow;

  configWindow = new BrowserWindow({
    width: 520,
    height: 320,
    resizable: false,
    minimizable: false,
    maximizable: false,
    modal: false,
    show: true,
    title: 'Bånder — Configure Server',
    webPreferences: {
      preload: join(__dirname, '../preload.mjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  attachLoadGuards(configWindow, 'Config window');

  const devServerUrl = process.env.VITE_DEV_SERVER_URL;
  if (devServerUrl) {
    // Serve from Vite: /config/index.html
    configWindow.loadURL(new URL('/config/index.html', devServerUrl).toString()).catch(err => {
      console.error('[fatal] Failed to load config URL', err);
      try {
        dialog.showMessageBoxSync(configWindow!, {
          type: 'error',
          title: 'Config window failed',
          message: 'Failed to load dev config URL',
          detail: String(err),
        });
      } catch {}
      app.exit(1);
    });
  } else {
    // Serve from built files
    configWindow.loadFile(join(__dirname, '../../dist/config/index.html')).catch(err => {
      console.error('[fatal] Failed to load built config HTML', err);
      try {
        dialog.showMessageBoxSync(configWindow!, {
          type: 'error',
          title: 'Config window failed',
          message: 'Failed to load built config HTML',
          detail: String(err),
        });
      } catch {}
      app.exit(1);
    });
  }

  configWindow.on('closed', () => (configWindow = null));
  return configWindow;
}

export function getConfigWindow() {
  return configWindow;
}
