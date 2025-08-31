import type { ElectronAPI } from '../src/preload/types/bridges';

declare global {
  interface Window {
    electron: ElectronAPI;
  }
}
