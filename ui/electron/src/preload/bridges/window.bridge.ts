import { contextBridge, ipcRenderer } from 'electron';

contextBridge.exposeInMainWorld('BaanderWindow', {
  minimize: () => ipcRenderer.invoke('baander:window:minimize'),
  maximize: () => ipcRenderer.invoke('baander:window:maximize'),
  close: () => ipcRenderer.invoke('baander:window:close'),
  isMaximized: () => ipcRenderer.invoke('baander:window:is-maximized'),
  onMaximizedChange: (cb: (maximized: boolean) => void) => {
    ipcRenderer.on('baander:window:maximized-change', (_e, val) => cb(val));
    return () => ipcRenderer.removeAllListeners('baander:window:maximized-change');
  },
});
