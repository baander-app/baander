import { contextBridge, ipcRenderer } from 'electron';
import { systemBridge } from './bridges/system.bridge';

contextBridge.exposeInMainWorld('electron', {
  system: systemBridge,
});

contextBridge.exposeInMainWorld('BaanderElectron', {
  config: {
    getServerUrl: async (): Promise<string> => {
      return ipcRenderer.invoke('baander:config:get-server-url');
    },
    setServerUrl: async (url: string): Promise<boolean> => {
      return ipcRenderer.invoke('baander:config:set-server-url', url);
    },
    finishSetup: async (): Promise<boolean> => {
      return ipcRenderer.invoke('baander:config:finish');
    },
  },
});
