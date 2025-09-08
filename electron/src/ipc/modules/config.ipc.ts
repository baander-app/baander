import { ipcMain } from 'electron';
import { installOrUpdateCorsShim } from '../../main/security/cors-shim';
import { createMainWindow } from '../../main/windows/main-window';
import { getConfigWindow } from '../../main/windows/config-window';
import type { IpcContext } from '../index';
import { getUser, setUser } from '../../shared/credentials';
import { initMainProcessImpl } from '../../shared/config-store';

import { app } from 'electron';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { mainLog } from '../../main/log';

const mainProcessImpl = initMainProcessImpl(app, fs, path);

export function registerConfigIpc(ctx: IpcContext) {
  if (!mainProcessImpl) {
    mainLog.error('Config IPC: mainProcessImpl is not available. Config functionality may be limited.');
  }

  ipcMain.handle('baander:config:get-server-url', () => {
    return mainProcessImpl?.getServerUrl() || '';
  });

  ipcMain.handle('baander:config:set-server-url', (_e, url: string) => {
    if (mainProcessImpl) {
      mainProcessImpl.setServerUrl(url || '');
      installOrUpdateCorsShim(url || '', ctx.rendererOrigin);
      return true;
    }
    return false;
  });

  ipcMain.handle('baander:config:get-user', (_, username: string) => getUser(username));

  ipcMain.handle('baander:config:set-user', async (_, username: string, password: string) => {
    try {
      const res = await setUser(username, password);
      mainLog.log('User saved:', res);
      return true;
    } catch (e) {
      mainLog.error('Failed to save user:', e);
      return false;
    }
  });

  // Close config window after main has finished loading
  ipcMain.handle('baander:config:finish', async () => {
    const main = createMainWindow();
    main.webContents.once('did-finish-load', () => {
      const cfg = getConfigWindow();
      if (cfg && !cfg.isDestroyed()) cfg.close();
      if (!main.isVisible()) main.show();
    });
    return true;
  });
}
