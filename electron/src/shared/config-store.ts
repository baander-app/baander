// Import platform utility properly - using a relative path since we're not using path aliases here
// We can use a simple detection method instead of depending on an external utility
// This avoids import issues in different contexts

type ConfigShape = {
  serverUrl?: string;
};

// Safely detect if we're in a renderer process with Electron APIs
const isElectronRenderer = typeof window !== 'undefined' &&
  typeof (window as any).BaanderElectron !== 'undefined';

// Main process implementation
// Only initialized in the main process context
let _mainProcessImpl: any = null;

// This will be populated in the main process through dynamic import
// We're exposing it as a named export for easier importing in the main process
export const mainProcessImpl = _mainProcessImpl;

// This function is only called in the main process
// It's used to initialize the mainProcessImpl from the main process
// This avoids using require() directly in the module scope
export function initMainProcessImpl(
  app: any,
  fs: { mkdirSync: Function, readFileSync: Function, writeFileSync: Function },
  path: { dirname: Function, join: Function },
): typeof _mainProcessImpl {
  try {
    const CONFIG_PATH = path.join(app.getPath('userData'), 'config.json');

    function ensureDir(filePath: string) {
      fs.mkdirSync(path.dirname(filePath), { recursive: true });
    }

    _mainProcessImpl = {
      loadConfig: (): ConfigShape => {
        try {
          const raw = fs.readFileSync(CONFIG_PATH, 'utf8');
          return JSON.parse(raw) as ConfigShape;
        } catch {
          return {};
        }
      },

      saveConfig: (config: ConfigShape) => {
        ensureDir(CONFIG_PATH);
        fs.writeFileSync(CONFIG_PATH, JSON.stringify(config, null, 2), 'utf8');
      },

      getServerUrl: (): string | undefined => {
        const cfg = _mainProcessImpl.loadConfig();
        return cfg.serverUrl;
      },

      setServerUrl: (url: string | undefined) => {
        const cfg = _mainProcessImpl.loadConfig();
        cfg.serverUrl = url;
        _mainProcessImpl.saveConfig(cfg);
      },
    };

    return _mainProcessImpl;
  } catch (err) {
    console.error('Failed to initialize main process config implementation:', err);
    return null;
  }
}

// Renderer-friendly implementation with async API
export async function loadConfig(): Promise<ConfigShape> {
  if (isElectronRenderer) {
    // Use the IPC bridge in renderer
    try {
      const serverUrl = await (window as any).BaanderElectron?.config?.getServerUrl?.();
      return { serverUrl };
    } catch (err) {
      console.error('Failed to load config via IPC:', err);
      return {};
    }
  } else if (_mainProcessImpl) {
    // Direct call in main process
    return _mainProcessImpl.loadConfig();
  }
  return {};
}

export async function saveConfig(config: ConfigShape): Promise<void> {
  if (isElectronRenderer) {
    // Use the IPC bridge in renderer
    try {
      if (config.serverUrl !== undefined) {
        await (window as any).BaanderElectron?.config?.setServerUrl?.(config.serverUrl);
      }
    } catch (err) {
      console.error('Failed to save config via IPC:', err);
    }
  } else if (_mainProcessImpl) {
    // Direct call in main process
    _mainProcessImpl.saveConfig(config);
  }
}

export async function getServerUrl(): Promise<string | undefined> {
  if (isElectronRenderer) {
    // Use the IPC bridge in renderer
    try {
      return await (window as any).BaanderElectron?.config?.getServerUrl?.();
    } catch (err) {
      console.error('Failed to get server URL via IPC:', err);
      return undefined;
    }
  } else if (_mainProcessImpl) {
    // Direct call in main process
    return _mainProcessImpl.getServerUrl();
  }
  return undefined;
}

export async function setServerUrl(url: string | undefined): Promise<void> {
  if (isElectronRenderer) {
    // Use the IPC bridge in renderer
    try {
      await (window as any).BaanderElectron?.config?.setServerUrl?.(url || '');
    } catch (err) {
      console.error('Failed to set server URL via IPC:', err);
    }
  } else if (_mainProcessImpl) {
    // Direct call in main process
    _mainProcessImpl.setServerUrl(url);
  }
}

// For backwards compatibility in non-async contexts (to be deprecated)
export function loadConfigSync(): ConfigShape {
  if (_mainProcessImpl) {
    return _mainProcessImpl.loadConfig();
  }
  return {};
}

export function getServerUrlSync(): string | null {
  if (_mainProcessImpl) {
    return _mainProcessImpl.getServerUrl();
  }
  return null;
}

