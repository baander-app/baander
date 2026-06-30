declare module '@fontsource/inter' {}
declare module '@fontsource/jetbrains-mono' {}

interface BaanderWindow {
  minimize: () => Promise<void>;
  maximize: () => Promise<void>;
  close: () => Promise<void>;
  isMaximized: () => Promise<boolean>;
  onMaximizedChange: (cb: (maximized: boolean) => void) => () => void;
}

interface BaanderElectron {
  config: {
    getServerUrl: () => Promise<string>;
    setServerUrl: (url: string) => Promise<boolean>;
    getUser: (username: string) => Promise<string | undefined>;
    setUser: (username: string, password: string) => Promise<void>;
    clearUser: () => Promise<void>;
    finishSetup: () => Promise<boolean>;
    getThemeMood: () => Promise<string>;
    setThemeMood: (mood: string) => Promise<boolean>;
  };
}

interface Window {
  __BAANDER_API_URL__: string;
  BaanderWindow?: BaanderWindow;
  BaanderElectron?: BaanderElectron;
}
