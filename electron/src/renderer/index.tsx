import { getServerUrl } from '../shared/config-store';
// @ts-expect-error - ziggy.js is generated from backend
import { Ziggy as ImportedZiggy } from '../../../resources/app/ziggy.js';
import { route } from 'ziggy-js';

function injectCSP(apiServer: string) {
  // Remove existing CSP tag if present
  const existingCSP = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
  if (existingCSP) {
    existingCSP.remove();
  }

  // Create new CSP meta tag with dynamic backend URL
  const cspTag = document.createElement('meta');
  cspTag.httpEquiv = 'Content-Security-Policy';
  cspTag.content = `
    default-src 'self';
    script-src 'self' 'unsafe-eval' blob: http://localhost:5173 ws://localhost:5173;
    style-src 'self' 'unsafe-inline' http://localhost:5173;
    img-src 'self' data: http://localhost:5173 ${apiServer};
    connect-src 'self' blob: ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://localhost:5173 ${apiServer} baander:;
    font-src 'self' data: http://localhost:5173;
    media-src 'self' blob: http://localhost:5173 ${apiServer};
    worker-src 'self' blob: baander:;
    object-src 'none';
    frame-src 'none';
  `;

  // Insert it into the head before any other elements
  document.head.insertBefore(cspTag, document.head.firstChild);
}

async function loadZiggy(apiServer: string): Promise<void> {
  // Set up Ziggy config with routes and server URL
  const ziggyConfig = {
    ...ImportedZiggy,
    url: apiServer,
  };
  window.Ziggy = ziggyConfig;

  // Make route() function globally available
  (window as any).route = (
    name: string,
    params?: string | number | boolean | Record<string, unknown> | null,
    absolute?: boolean
  ) => {
    return route(name, params as any, absolute, ziggyConfig);
  };
}

async function bootstrap() {
  const apiServer = await getServerUrl();

  if (!apiServer) {
    throw new Error('No server URL configured. Please complete the setup first.');
  }

  injectCSP(apiServer);
  await loadZiggy(apiServer);

  // Now import and start the real app entry
  await import('@/app/index.tsx');
}

bootstrap().catch((e) => {
  console.error('[bootstrap] Unhandled error:', e);
  import('@/app/index.tsx');
});
