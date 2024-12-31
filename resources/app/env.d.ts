/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
  readonly VITE_APP_URL: string;
  readonly VITE_APP_ENV: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare interface Window {
  BaanderAppInfo: {
    name: string;
    url: string;
    environment: 'local' | 'production' | 'testing';
    debug: boolean;
    locale: string;
    version: string;
  };
}