/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
  readonly VITE_APP_URL: string;
  readonly VITE_APP_ENV: string;
  readonly VITE_OTL_ENDPOINT: string;
  readonly VITE_OTL_SERVICE_NAME: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
