/// <reference types="vite-plugin-react-rich-svg/client" />
/// <reference types="vite/client" />
/// <reference types="unplugin-info/client" />

global {
  let LARAVEL_TRANSLATIONS: any;

  interface Window {
    BaanderAppInfo: {
      name: string;
      environment: 'local' | 'production' | 'testing';
      debug: boolean;
      locale: string;
    }
  }
}

