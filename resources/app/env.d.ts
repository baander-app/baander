/// <reference types="vite/client" />
/// <reference path="../../packages/dsp/dsp.d.ts" />

interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
  readonly VITE_APP_URL: string;
  readonly VITE_APP_ENV: string;
  readonly VITE_APP_RUNTIME: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare interface Window {
  BaanderAppConfig: {
    name: string;
    url: string;
    apiUrl: string;
    apiDocsUrl: string;
    environment: 'local' | 'production' | 'testing';
    debug: boolean;
    locale: string;
    version: string;
  };

  BaanderElectron?: {
    config: {
      getServerUrl(): Promise<string>;
      setServerUrl(url: string): Promise<boolean>;
      getUser(username: string): Promise<string | undefined>;
      setUser(username: string, password: string): Promise<void>;
      clearUser(username: string): Promise<void>;
      finishSetup(): Promise<boolean>;
    };
  };
}
