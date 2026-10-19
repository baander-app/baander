import { BrowserWindow, app, dialog } from 'electron';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mainLog } from '../log';
import { secureAppWindow } from '../security/harden';
import { createRendererTrust } from '../security/renderer-trust';

let configWindow: BrowserWindow | null = null;

// __dirname for ESM bundles
const __dirname = dirname(fileURLToPath(import.meta.url));

function attachLoadGuards(win: BrowserWindow, label: string) {
  // Crash the app if the page fails to load, but first show an alert
  win.webContents.on('did-fail-load', (_e, errorCode, errorDescription, validatedURL) => {
    mainLog.error(`[fatal] ${label} failed to load`, { errorCode, errorDescription, validatedURL });
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

  // Also surface preload script errors
  win.webContents.on('preload-error', (_e, preloadPath, error) => {
    mainLog.error(`[fatal] ${label} preload error`, { preloadPath, error });
    try {
      dialog.showMessageBoxSync(win, {
        type: 'error',
        title: `${label} preload failed`,
        message: `${label} preload script error`,
        detail: `Preload: ${preloadPath}\n${(error && (error.stack || error.message)) || String(error)}`,
      });
    } catch {}
    app.exit(1);
  });

  // Surface renderer crashes and OOMs
  win.webContents.on('render-process-gone', (_e, details) => {
    mainLog.error(`[fatal] ${label} renderer gone`, details);
    try {
      dialog.showMessageBoxSync(win, {
        type: 'error',
        title: `${label} crashed`,
        message: `${label} renderer process terminated`,
        detail: `Reason: ${details.reason}\nExit code: ${details.exitCode}\n${details.reason === 'oom' ? 'Out of memory.' : ''}`,
      });
    } catch {}
    app.exit(1);
  });

  // Warn when the page becomes unresponsive
  win.on('unresponsive', () => {
    mainLog.error(`[fatal] ${label} became unresponsive`);
    try {
      dialog.showMessageBoxSync(win, {
        type: 'error',
        title: `${label} unresponsive`,
        message: `${label} is not responding`,
        detail: 'Please wait or restart the application.',
      });
    } catch {}
  });

  // Crash the app if it doesn't finish loading in time, but first show an alert
  const watchdog = setTimeout(() => {
    mainLog.error(`[fatal] ${label} did not finish loading in time`);
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
    backgroundColor: '#fff',
    width: 520,
    height: 320,
    resizable: false,
    minimizable: false,
    maximizable: false,
    modal: false,
    show: true,
    title: 'Bånder — Configure Server',
    webPreferences: {
      preload: app.isPackaged
        ? join(__dirname, 'preload.cjs')  // Production: same directory (flattened)
        : join(__dirname, '../preload/preload.cjs'),  // go up from dist/main to dist, then preload/
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  const rendererDirectory = app.isPackaged ? app.getAppPath() : join(__dirname, '../renderer');
  const trust = createRendererTrust({
    devServerUrl: process.env.VITE_DEV_SERVER_URL,
    mainFilePath: join(rendererDirectory, 'index.html'),
    configFilePath: join(rendererDirectory, 'config/index.html'),
  });
  secureAppWindow(configWindow, trust);

  attachLoadGuards(configWindow, 'Config window');

  const loadDescription = process.env.VITE_DEV_SERVER_URL ? 'config URL' : 'built config HTML';
  configWindow.loadURL(trust.configUrl).catch(err => {
    mainLog.error(`[fatal] Failed to load ${loadDescription}`, err);
    try {
      dialog.showMessageBoxSync(configWindow!, {
        type: 'error',
        title: 'Config window failed',
        message: `Failed to load ${loadDescription}`,
        detail: String(err),
      });
    } catch {}
    app.exit(1);
  });

  configWindow.on('closed', () => (configWindow = null));
  return configWindow;
}

export function getConfigWindow() {
  return configWindow;
}
