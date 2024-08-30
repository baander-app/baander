import 'clockwork-browser/metrics';
import 'clockwork-browser/toolbar';

import '@mantine/core/styles.css';
import 'mantine-contextmenu/styles.css';
import '@mantine/carousel/styles.css';
import '@mantine/dates/styles.css';
import '@mantine/dropzone/styles.css';
import '@mantine/notifications/styles.css';
import '@mantine/nprogress/styles.css';
import '@mantine/tiptap/styles.css';
import 'overlayscrollbars/overlayscrollbars.css';

import 'dayjs/locale/da';
import 'dayjs/locale/de';
import 'dayjs/locale/en';

import { applyInterceptors } from '@/api-client-ext/interceptors';
import { OpenAPI as OpenAPIConfig } from '@/api-client/requests';
import { Token } from '@/services/auth/token.ts';


OpenAPIConfig.BASE = `${import.meta.env.VITE_APP_URL}/api`;
OpenAPIConfig.CREDENTIALS = 'same-origin';
OpenAPIConfig.HEADERS = {
  'accept': 'application/json',
  'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')!.getAttribute('content') as string,
  'X-Requested-With': 'XMLHttpRequest',
};
OpenAPIConfig.TOKEN = Token.get()?.accessToken.token;

applyInterceptors();
