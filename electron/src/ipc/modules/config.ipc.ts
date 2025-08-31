import { ipcMain } from 'electron';
import { getServerUrl, setServerUrl } from '../../main/../shared/config-store';
import { installOrUpdateCorsShim } from '../../main/security/cors-shim';
import { createMainWindow } from '../../main/windows/main-window';
import { getConfigWindow } from '../../main/windows/config-window';
import type { IpcContext } from '../index';

export function registerConfigIpc(ctx: IpcContext) {
  ipcMain.handle('baander:config:get-server-url', () => getServerUrl() || '');

  ipcMain.handle('baander:config:set-server-url', (_e, url: string) => {
    setServerUrl(url || '');
    installOrUpdateCorsShim(url || '', ctx.rendererOrigin);
    return true;
  });

  ipcMain.handle('baander:config:get-user-name', () =>
  )

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
