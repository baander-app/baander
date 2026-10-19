import { ipcMain, type IpcMainInvokeEvent } from 'electron';
import { getTrustedWindow } from '../main/security/renderer-trust';

export function handleTrusted(channel: string, handler: (event: IpcMainInvokeEvent, ...args: unknown[]) => unknown) {
  ipcMain.handle(channel, (event, ...args) => {
    if (!getTrustedWindow(event)) throw new Error('Unauthorized IPC sender');
    return handler(event, ...args);
  });
}
