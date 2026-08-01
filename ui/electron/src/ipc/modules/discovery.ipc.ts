import { BrowserWindow, ipcMain } from 'electron';
import { getDiscoveryService } from '../../main/services/discovery.service';

export function registerDiscoveryIpc(): void {
  const discovery = getDiscoveryService();

  // Start discovery scan
  ipcMain.handle('baander:discovery:start-scan', async () => {
    await discovery.startScan();
    return { success: true };
  });

  // Stop discovery scan
  ipcMain.handle('baander:discovery:stop-scan', async () => {
    discovery.stopScan();
    return { success: true };
  });

  // Get discovered servers
  ipcMain.handle('baander:discovery:get-servers', async () => {
    return discovery.getServers();
  });

  // Check if scanning is active
  ipcMain.handle('baander:discovery:is-scanning', async () => {
    return discovery.isScanningActive();
  });

  // Listen for server discovery events
  discovery.on('server-found', (server) => {
    // Notify all renderer processes
    const windows = BrowserWindow.getAllWindows();
    windows.forEach((win) => {
      if (!win.isDestroyed()) {
        win.webContents.send('baander:discovery:server-found', server);
      }
    });
  });

  discovery.on('scan-stopped', (servers) => {
    const windows = BrowserWindow.getAllWindows();
    windows.forEach((win) => {
      if (!win.isDestroyed()) {
        win.webContents.send('baander:discovery:scan-stopped', servers);
      }
    });
  });

  discovery.on('scan-started', () => {
    const windows = BrowserWindow.getAllWindows();
    windows.forEach((win) => {
      if (!win.isDestroyed()) {
        win.webContents.send('baander:discovery:scan-started');
      }
    });
  });
}