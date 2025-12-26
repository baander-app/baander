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

import '@fontsource-variable/inter';
import '@fontsource-variable/source-code-pro';
import { initAuthStore } from '@/app/modules/auth/store';

initAuthStore();

import { client } from 'laravel-precognition-react';
import { AXIOS_INSTANCE } from '@/app/libs/api-client/axios-instance.ts';

client.use(AXIOS_INSTANCE);

// Configure Precognition to only validate, not submit
// Actual submission is handled by React Query mutations
client.determineSuccessUsing(() => false);