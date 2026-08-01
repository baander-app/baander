import { ipcMain, type BrowserWindow } from 'electron';

export function registerWindowIpc(mainWindow: BrowserWindow) {
  ipcMain.handle('baander:window:minimize', () => mainWindow.minimize());

  ipcMain.handle('baander:window:maximize', () => {
    if (mainWindow.isMaximized()) {
      mainWindow.unmaximize();
    } else {
      mainWindow.maximize();
    }
  });

  ipcMain.handle('baander:window:close', () => mainWindow.close());

  ipcMain.handle('baander:window:is-maximized', () => mainWindow.isMaximized());

  mainWindow.on('maximize', () => {
    mainWindow.webContents.send('baander:window:maximized-change', true);
  });

  mainWindow.on('unmaximize', () => {
    mainWindow.webContents.send('baander:window:maximized-change', false);
  });

  ipcMain.handle('baander:window:set-background-color', (_e, color: string) => {
    mainWindow.setBackgroundColor(color);
  });
}
