/// <reference types="vite-plugin-react-rich-svg/client" />
/// <reference types="vite/client" />
import Pusher from 'pusher-js';


global {
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

