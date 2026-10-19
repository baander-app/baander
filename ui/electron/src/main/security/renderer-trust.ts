import type { BrowserWindow, IpcMainEvent, WebContents } from 'electron';
import { pathToFileURL } from 'node:url';

export interface RendererTrust {
  mainUrl: string;
  configUrl: string;
  isTrusted: (url: string) => boolean;
}

export function createRendererTrust(options: {
  devServerUrl?: string;
  mainFilePath: string;
  configFilePath: string;
}): RendererTrust {
  const mainUrl = options.devServerUrl
    ? new URL(options.devServerUrl).href
    : pathToFileURL(options.mainFilePath).href;
  const configUrl = options.devServerUrl
    ? new URL('/config/index.html', mainUrl).href
    : pathToFileURL(options.configFilePath).href;
  const devOrigin = options.devServerUrl ? new URL(mainUrl).origin : null;
  const documents = new Set([mainUrl, configUrl]);

  return {
    mainUrl,
    configUrl,
    isTrusted(value) {
      try {
        const url = new URL(value);
        if (url.username || url.password) return false;
        if (devOrigin !== null) {
          return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === devOrigin;
        }
        url.search = '';
        url.hash = '';
        return url.protocol === 'file:' && documents.has(url.href);
      } catch {
        return false;
      }
    },
  };
}

const ownedWindows = new WeakMap<WebContents, { window: BrowserWindow; trust: RendererTrust }>();

export function registerTrustedWindow(window: BrowserWindow, trust: RendererTrust) {
  const contents = window.webContents;
  ownedWindows.set(contents, { window, trust });
  window.once('closed', () => ownedWindows.delete(contents));
}

export function getTrustedWindow(event: Pick<IpcMainEvent, 'sender' | 'senderFrame'>): BrowserWindow | null {
  const owned = ownedWindows.get(event.sender);
  if (!owned || owned.window.isDestroyed() || event.sender.isDestroyed()) return null;
  const frame = event.senderFrame;
  if (!frame || frame.detached || frame !== event.sender.mainFrame) return null;
  if (!owned.trust.isTrusted(frame.url) || !owned.trust.isTrusted(event.sender.getURL())) return null;
  return owned.window;
}
