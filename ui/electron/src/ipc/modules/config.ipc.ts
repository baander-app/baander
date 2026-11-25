import { handleTrusted } from '../guard';
import { app } from 'electron';
import { installOrUpdateCorsShim } from '../../main/security/cors-shim';
import { createMainWindow, getMainWindow } from '../../main/windows/main-window';
import { createConfigWindow, getConfigWindow } from '../../main/windows/config-window';
import type { IpcContext } from '../index';
import { clearUser, setUser } from '../../shared/credentials';
import { initMainProcessImpl } from '../../shared/config-store';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { mainLog } from '../../main/log';

const mainProcessImpl = initMainProcessImpl(app, fs, path);

function validateUsername(value: unknown): asserts value is string {
  if (typeof value !== 'string' || value.length === 0 || value.includes('\0')) throw new Error('Invalid username');
}

/** The renderer interpolates the stored value into its content policy, so only an origin is stored. */
function serverOrigin(value: unknown): string {
  if (typeof value !== 'string') throw new Error('Invalid server URL');
  if (value === '') return '';
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error('Invalid server URL');
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') throw new Error('Invalid server URL');
  return parsed.origin;
}

export function registerConfigIpc(ctx: IpcContext) {
  if (!mainProcessImpl) {
    mainLog.error('Config IPC: mainProcessImpl is not available. Config functionality may be limited.');
  }

  handleTrusted('baander:config:get-server-url', () => {
    return mainProcessImpl?.getServerUrl() || '';
  });

  handleTrusted('baander:config:set-server-url', (_e, url: unknown) => {
    const origin = serverOrigin(url);
    if (mainProcessImpl) {
      mainProcessImpl.setServerUrl(origin);
      installOrUpdateCorsShim(origin, ctx.rendererOrigin);
      return true;
    }
    return false;
  });

  handleTrusted('baander:config:set-user', async (_, username: unknown, password: unknown) => {
    validateUsername(username);
    if (typeof password !== 'string') throw new Error('Invalid password');
    try {
      await setUser(username, password);
      return true;
    } catch (e) {
      mainLog.error('Failed to save user:', e);
      return false;
    }
  });

  handleTrusted('baander:config:clear-user', async (_, username: unknown) => {
    validateUsername(username);
    try {
      const res = await clearUser(username);
      return res;
    } catch (e) {
      mainLog.error('Failed to clear user:', e);
      return false;
    }
  });

  // The main window offers this when it cannot start; the next finish creates a fresh main window.
  handleTrusted('baander:config:open-setup', () => {
    createConfigWindow();
    const main = getMainWindow();
    if (main && !main.isDestroyed()) main.close();
    return true;
  });

  let finishing = false;

  // Close config window after main has finished loading
  handleTrusted('baander:config:finish', async () => {
    if (finishing) return true;
    finishing = true;
    createMainWindow((main) => {
      const cfg = getConfigWindow();
      if (cfg && !cfg.isDestroyed()) cfg.close();
      if (!main.isVisible()) main.show();
      finishing = false;
    });
    return true;
  });

  handleTrusted('baander:config:get-theme-mood', () => {
    return mainProcessImpl?.getThemeMood() || 'dark';
  });

  handleTrusted('baander:config:set-theme-mood', (_e, mood: unknown) => {
    if (typeof mood !== 'string' || !['dark', 'warm', 'cool', 'balanced'].includes(mood)) throw new Error('Invalid theme mood');
    if (mainProcessImpl) {
      mainProcessImpl.setThemeMood(mood);
      return true;
    }
    return false;
  });
}
