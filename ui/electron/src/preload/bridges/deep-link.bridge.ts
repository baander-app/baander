import { ipcRenderer } from 'electron';

export const deepLinkBridge = {
  getPendingUrl: async (): Promise<string | null> => {
    return ipcRenderer.invoke('baander:deeplink:get-pending-url');
  },

  clearPendingUrl: async (): Promise<boolean> => {
    return ipcRenderer.invoke('baander:deeplink:clear-pending-url');
  },

  onDeepLinkReceived: (callback: (url: string) => void) => {
    const listener = (_event: Electron.IpcRendererEvent, url: string) => callback(url);
    ipcRenderer.on('baander:deeplink:received', listener);
    return () => ipcRenderer.removeListener('baander:deeplink:received', listener);
  },
} as const;

export type DeepLinkBridge = typeof deepLinkBridge;
