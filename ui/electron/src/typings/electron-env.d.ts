import type { BaanderElectronAPI } from '../src/preload/bridges/types/bridges';
import type { ElectronAPI } from '../src/preload/types/bridges';

declare global {
  interface Window {
    electron: ElectronAPI;
    BaanderElectron: BaanderElectronAPI;
    process?: {
      type: 'renderer' | 'browser';
    };
  }
}
