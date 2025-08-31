import { BrowserWindow, app, dialog } from 'electron';
import { join } from 'node:path';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

let mainWindow: BrowserWindow | null = null;

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
  }, 20000);

  win.webContents.once('did-finish-load', () => clearTimeout(watchdog));
}

export function
createMainWindow() {
  if (mainWindow) return mainWindow;

  mainWindow = new BrowserWindow({
    width: 1200,
    height: 800,
    show: false,
    webPreferences: {
      // point to emitted preload file (electron/dist-electron/preload.mjs)
      preload: join(__dirname, '../preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
      backgroundThrottling: false,
    },
  });

  attachLoadGuards(mainWindow, 'Main window');

  const devServerUrl = process.env.VITE_DEV_SERVER_URL;
  if (devServerUrl) {
    mainWindow.loadURL(devServerUrl).catch(err => {
      console.error('[fatal] Failed to load dev server URL', err);
      try {
        dialog.showMessageBoxSync(mainWindow!, {
          type: 'error',
          title: 'Main window failed',
          message: 'Failed to load dev server URL',
          detail: String(err),
        });
      } catch {}
      app.exit(1);
    });
  } else {
    // __dirname is electron/dist-electron/main in production
    mainWindow.loadFile(join(__dirname, '../../dist/index.html')).catch(err => {
      console.error('[fatal] Failed to load built index.html', err);
      try {
        dialog.showMessageBoxSync(mainWindow!, {
          type: 'error',
          title: 'Main window failed',
          message: 'Failed to load built index.html',
          detail: String(err),
        });
      } catch {}
      app.exit(1);
    });
  }

  mainWindow.once('ready-to-show', () => mainWindow?.show());
  mainWindow.on('closed', () => (mainWindow = null));

  return mainWindow;
}

(createMainWindow as any).get = () => mainWindow;
