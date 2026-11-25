interface BaanderWindow {
  minimize: () => Promise<void>;
  maximize: () => Promise<void>;
  close: () => Promise<void>;
  isMaximized: () => Promise<boolean>;
  onMaximizedChange: (cb: (maximized: boolean) => void) => () => void;
  /** Whether the desktop window is shown and not minimised. */
  isVisible: () => Promise<boolean>;
  onVisibilityChange: (cb: (visible: boolean) => void) => () => void;
}

interface BaanderElectron {
  config: {
    getServerUrl: () => Promise<string>;
    setServerUrl: (url: string) => Promise<boolean>;
    setUser: (username: string, password: string) => Promise<boolean>;
    clearUser: (username: string) => Promise<boolean>;
    finishSetup: () => Promise<boolean>;
    /** Opens the server configuration window and closes the main window. */
    openSetup: () => Promise<boolean>;
    getThemeMood: () => Promise<string>;
    setThemeMood: (mood: string) => Promise<boolean>;
  };
}

interface Window {
  __BAANDER_API_URL__: string;
  BaanderWindow?: BaanderWindow;
  BaanderElectron?: BaanderElectron;
}
