export const systemBridge = {
  isElectron: () => true,
  version: () => (process.versions.electron ?? 'unknown'),
};
