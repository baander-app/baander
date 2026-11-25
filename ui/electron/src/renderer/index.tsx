import { getServerUrl } from '../shared/config-store';
import { startRenderer } from './bootstrap';

startRenderer({
  document,
  isDevServer: location.protocol === 'http:',
  getServerUrl,
  publishApiUrl: (origin) => {
    // The shared API client reads this when its module loads, so it is set before the import.
    window.__BAANDER_API_URL__ = origin;
  },
  loadApp: () => import('@/app/main.tsx'),
  openSetup: async () => window.BaanderElectron?.config.openSetup(),
}).catch((error: unknown) => {
  console.error('[bootstrap] Unhandled error:', error);
});
