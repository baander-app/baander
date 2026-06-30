import { ipcMain } from 'electron';
import type { IpcContext } from '../index';

export function registerDeepLinkIpc(ctx: IpcContext) {
  ipcMain.handle('baander:deeplink:get-pending-url', () => {
    return ctx.deepLinkService?.getPendingUrl() || null;
  });

  ipcMain.handle('baander:deeplink:clear-pending-url', () => {
    ctx.deepLinkService?.clearPendingUrl();
    return true;
  });
}
