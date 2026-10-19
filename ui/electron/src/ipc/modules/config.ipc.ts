import { handleTrusted } from '../guard';
import { app } from 'electron';
import { installOrUpdateCorsShim } from '../../main/security/cors-shim';
import { createMainWindow } from '../../main/windows/main-window';
import { getConfigWindow } from '../../main/windows/config-window';
import type { IpcContext } from '../index';
import { clearUser, getUser, setUser } from '../../shared/credentials';
import { initMainProcessImpl } from '../../shared/config-store';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { mainLog } from '../../main/log';

const mainProcessImpl = initMainProcessImpl(app, fs, path);

function validateUsername(value: unknown): asserts value is string {
  if (typeof value !== 'string' || value.length === 0 || value.includes('\0')) throw new Error('Invalid username');
}

export function registerConfigIpc(ctx: IpcContext) {
  if (!mainProcessImpl) {
    mainLog.error('Config IPC: mainProcessImpl is not available. Config functionality may be limited.');
  }

  handleTrusted('baander:config:get-server-url', () => {
    return mainProcessImpl?.getServerUrl() || '';
  });

  handleTrusted('baander:config:set-server-url', (_e, url: unknown) => {
    if (typeof url !== 'string') throw new Error('Invalid server URL');
    if (url !== '') {
      let parsed: URL;
      try { parsed = new URL(url); } catch { throw new Error('Invalid server URL'); }
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') throw new Error('Invalid server URL');
    }
    if (mainProcessImpl) {
      mainProcessImpl.setServerUrl(url);
      installOrUpdateCorsShim(url, ctx.rendererOrigin);
      return true;
    }
    return false;
  });

  handleTrusted('baander:config:get-user', (_, username: unknown) => {
    validateUsername(username);
    return getUser(username);
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
