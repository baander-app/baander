import type { SystemBridge } from '../system.bridge';
import type { DeepLinkBridge } from '../deep-link.bridge';
import type { DiscoveryBridge } from '../discovery.bridge';
import type { PlaybackBridge } from '../playback.bridge';


export type ElectronAPI = {
  deepLink: DeepLinkBridge;
  system: SystemBridge;
  playback: PlaybackBridge;
  ipcRenderer: {
    invoke: (channel: string, ...args: any[]) => Promise<any>;
    on: (channel: string, func: (...args: any[]) => void) => void;
    removeListener: (channel: string, func: (...args: any[]) => void) => void;
  };
};

export type BaanderElectronAPI = {
  config: {
    getServerUrl: () => Promise<string>;
    setServerUrl: (url: string) => Promise<boolean>;
    getUser: (username: string) => Promise<string | undefined>;
    setUser: (username: string, password: string) => Promise<void>;
    clearUser: () => Promise<void>;
    finishSetup: () => Promise<boolean>;
  };
  discovery: DiscoveryBridge;
};

export type BaanderWindowAPI = {
  minimize: () => Promise<void>;
  maximize: () => Promise<void>;
  close: () => Promise<void>;
  isMaximized: () => Promise<boolean>;
  onMaximizedChange: (cb: (maximized: boolean) => void) => () => void;
};
