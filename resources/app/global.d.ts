import { AxiosInstance } from 'axios';
import { route as routeFn } from 'ziggy-js';
import { Ziggy } from '@/ziggy';

declare global {
  interface Window {
    axios: AxiosInstance;
    Ziggy: typeof Ziggy;
  }

  var route: typeof routeFn;
}
