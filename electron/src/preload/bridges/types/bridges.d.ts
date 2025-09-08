import type { SystemBridge } from '../system.bridge';
import type { DeepLinkBridge } from '../deep-link.bridge';


export type ElectronAPI = {
  deepLink: DeepLinkBridge;
  system: SystemBridge;
  ipcRenderer: {
    invoke: (channel: string, ...args: any[]) => Promise<any>;
    on: (channel: string, func: (...args: any[]) => void) => void;
    removeListener: (channel: string, func: (...args: any[]) => void) => void;
  };
};
