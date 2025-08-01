// External CSS imports
import 'overlayscrollbars/overlayscrollbars.css';

// dayjs and its plugins
import dayjs from 'dayjs';
import duration from 'dayjs/plugin/duration';
import relativeTime from 'dayjs/plugin/relativeTime';
import localizedFormat from 'dayjs/plugin/localizedFormat';

// Import dayjs locales
import 'dayjs/locale/da';
import 'dayjs/locale/de';
import 'dayjs/locale/en';
import 'dayjs/locale/en-gb';
import 'dayjs/locale/es';
import 'dayjs/locale/th';
import 'dayjs/locale/zh-cn';

// Extend dayjs with plugins
dayjs.extend(duration);
dayjs.extend(relativeTime);
dayjs.extend(localizedFormat);

// Internal imports
import { applyInterceptors } from '@/api-client-ext/interceptors';
import { OpenAPI as OpenAPIConfig } from '@/api-client/requests';
import { Token } from '@/services/auth/token.ts';


// OpenAPI configuration
OpenAPIConfig.BASE = `${import.meta.env.VITE_APP_URL}`;
OpenAPIConfig.CREDENTIALS = 'same-origin';
OpenAPIConfig.HEADERS = {
  'accept': 'application/json',
  'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')!.getAttribute('content') as string,
  'X-Requested-With': 'XMLHttpRequest',
};
OpenAPIConfig.TOKEN = Token.get()?.accessToken.token;

// Apply request interceptors
applyInterceptors();