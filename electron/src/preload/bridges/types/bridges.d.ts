export type SystemBridge = {
  isElectron: () => boolean;
  version: () => string;
};

export type ElectronAPI = {
  system: SystemBridge;
};
