import { ipcRenderer } from 'electron';

export const deepLinkBridge = {
  getPendingUrl: async (): Promise<string | null> => {
    return ipcRenderer.invoke('baander:deeplink:get-pending-url');
  },

  clearPendingUrl: async (): Promise<boolean> => {
    return ipcRenderer.invoke('baander:deeplink:clear-pending-url');
  },

  onDeepLinkReceived: (callback: (url: string) => void) => {
    ipcRenderer.on('baander:deeplink:received', (_event, url) => callback(url));
  },

  removeDeepLinkListener: (callback: (url: string) => void) => {
    ipcRenderer.removeListener('baander:deeplink:received', callback);
  },
} as const;

export type DeepLinkBridge = typeof deepLinkBridge;
