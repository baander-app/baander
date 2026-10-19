import { session } from 'electron';
import type { BrowserWindow } from 'electron';
import { registerTrustedWindow, type RendererTrust } from './renderer-trust';

export function secureAppWindow(window: BrowserWindow, trust: RendererTrust) {
  registerTrustedWindow(window, trust);
  const contents = window.webContents;
  contents.on('will-navigate', (event, url) => {
    if (!trust.isTrusted(url)) event.preventDefault();
  });
  contents.on('will-frame-navigate', event => {
    if (!trust.isTrusted(event.url)) event.preventDefault();
  });
  contents.on('will-redirect', (event, url) => {
    if (!trust.isTrusted(url)) event.preventDefault();
  });
  contents.setWindowOpenHandler(() => ({ action: 'deny' }));
}

export function hardenSecurity() {
  session.defaultSession.setPermissionRequestHandler((_contents, _permission, callback) => {
    callback(false);
  });
  session.defaultSession.setPermissionCheckHandler(() => false);
}
