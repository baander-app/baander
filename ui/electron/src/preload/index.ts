import { contextBridge, ipcRenderer } from 'electron';
import { deepLinkBridge } from './bridges/deep-link.bridge';
import { discoveryBridge } from './bridges/discovery.bridge';
import { systemBridge } from './bridges/system.bridge';
import { playbackBridge } from './bridges/playback.bridge';
import './bridges/window.bridge';

contextBridge.exposeInMainWorld('electron', {
  deepLink: deepLinkBridge,
  system: systemBridge,
  playback: playbackBridge,
});

contextBridge.exposeInMainWorld('BaanderElectron', {
  config: {
    getServerUrl: async (): Promise<string> => {
      return ipcRenderer.invoke('baander:config:get-server-url');
    },
    setServerUrl: async (url: string): Promise<boolean> => {
      return ipcRenderer.invoke('baander:config:set-server-url', url);
    },
    getUser: async (username: string): Promise<string |undefined> => {
      return ipcRenderer.invoke('baander:config:get-user', username);
    },
    setUser: async (username: string, password: string): Promise<void> => {
      return ipcRenderer.invoke('baander:config:set-user', username, password);
    },
    clearUser: async (): Promise<void> => {
      return ipcRenderer.invoke('baander:config:clear-user');
    },
    finishSetup: async (): Promise<boolean> => {
      return ipcRenderer.invoke('baander:config:finish');
    },
    getThemeMood: async (): Promise<string> => {
      return ipcRenderer.invoke('baander:config:get-theme-mood');
    },
    setThemeMood: async (mood: string): Promise<boolean> => {
      return ipcRenderer.invoke('baander:config:set-theme-mood', mood);
    },
  },
  discovery: discoveryBridge,
});
