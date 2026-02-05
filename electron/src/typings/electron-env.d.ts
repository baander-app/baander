import type { ElectronAPI } from '../src/preload/types/bridges';

declare global {
  interface Window {
    electron: ElectronAPI;
    Ziggy?: {
      url: string;
      port: number | null;
      defaults: Record<string, unknown>;
      routes: Record<string, {
        uri: string;
        methods: string[];
        parameters?: string[];
        wheres?: Record<string, string>;
        bindings?: Record<string, string>;
      }>;
    };
    route: (
      name: string,
      params?: string | number | boolean | Record<string, unknown> | null,
      absolute?: boolean
    ) => string;
    process?: {
      type: 'renderer' | 'browser';
    };
  }
}

declare module '/ziggy.js' {
  export const Ziggy: Window['Ziggy'];
}
