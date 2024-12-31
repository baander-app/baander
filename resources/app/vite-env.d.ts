/// <reference types="vite-plugin-react-rich-svg/client" />
/// <reference types="vite/client" />
/// <reference types="unplugin-info/client" />
import Pusher from 'pusher-js';

global {
  let LARAVEL_TRANSLATIONS: any;

  interface Window {
    Pusher: typeof Pusher;

    BaanderAppInfo: {
      name: string;
      environment: 'local' | 'production' | 'testing';
      debug: boolean;
      locale: string;
    }
  }
}

