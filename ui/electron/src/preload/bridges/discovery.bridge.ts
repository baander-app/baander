import { ipcRenderer } from 'electron';

export interface DiscoveredServer {
  name: string;
  url: string;
  version: string;
  apiVersion: string;
  timestamp: string;
  lastSeen: number;
}

export type DiscoveryListener = (servers: DiscoveredServer[]) => void;

export const discoveryBridge = {
  startScan: async (): Promise<{ success: boolean }> => {
    return ipcRenderer.invoke('baander:discovery:start-scan');
  },

  stopScan: async (): Promise<{ success: boolean }> => {
    return ipcRenderer.invoke('baander:discovery:stop-scan');
  },

  getServers: async (): Promise<DiscoveredServer[]> => {
    return ipcRenderer.invoke('baander:discovery:get-servers');
  },

  isScanning: async (): Promise<boolean> => {
    return ipcRenderer.invoke('baander:discovery:is-scanning');
  },

  onServerFound: (callback: (server: DiscoveredServer) => void) => {
    const listener = (_event: unknown, server: DiscoveredServer) => callback(server);
    ipcRenderer.on('baander:discovery:server-found', listener);

    // Return unsubscribe function
    return () => ipcRenderer.removeListener('baander:discovery:server-found', listener as any);
  },

  onScanStopped: (callback: (servers: DiscoveredServer[]) => void) => {
    const listener = (_event: unknown, servers: DiscoveredServer[]) => callback(servers);
    ipcRenderer.on('baander:discovery:scan-stopped', listener);

    return () => ipcRenderer.removeListener('baander:discovery:scan-stopped', listener as any);
  },

  onScanStarted: (callback: () => void) => {
    const listener = () => callback();
    ipcRenderer.on('baander:discovery:scan-started', listener);

    return () => ipcRenderer.removeListener('baander:discovery:scan-started', listener as any);
  },
} as const;

export type DiscoveryBridge = typeof discoveryBridge;