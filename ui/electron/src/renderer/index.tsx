import { getServerUrl } from '../shared/config-store';

/**
 * Dev server port range for Vite. The Electron dev server can be assigned
 * any port in this range. CSP must allow all of them.
 */
const DEV_PORT_MIN = 5150;
const DEV_PORT_MAX = 5200;

/** Generate CSP origin strings for the full dev port range. */
function devPortOrigins(): string {
  const origins: string[] = [];
  for (let port = DEV_PORT_MIN; port <= DEV_PORT_MAX; port++) {
    origins.push(`http://localhost:${port}`);
    origins.push(`ws://localhost:${port}`);
  }
  return origins.join(' ');
}

function injectCSP(apiServer: string) {
  const existingCSP = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
  if (existingCSP) existingCSP.remove();

  const isDev = location.protocol === 'http:';
  const devOrigins = isDev ? devPortOrigins() : '';

  const cspTag = document.createElement('meta');
  cspTag.httpEquiv = 'Content-Security-Policy';
  cspTag.content = [
    `default-src 'self'`,
    `script-src 'self' 'unsafe-eval' blob: ${devOrigins}`,
    `style-src 'self' 'unsafe-inline' ${devOrigins}`,
    // blob: for album art via object URLs, https: for external station logos/covers
    `img-src 'self' data: blob: https: ${devOrigins} ${apiServer}`,
    `connect-src 'self' blob: https: ${devOrigins} ${apiServer} baander:`,
    `font-src 'self' data: ${devOrigins}`,
    `media-src 'self' blob: ${devOrigins} ${apiServer}`,
    `worker-src 'self' blob: baander:`,
    `object-src 'none'`,
    `frame-src 'none'`,
  ].join('; ');

  document.head.insertBefore(cspTag, document.head.firstChild);
}

async function bootstrap() {
  const apiServer = await getServerUrl();

  if (!apiServer) {
    throw new Error('No server URL configured. Please complete the setup first.');
  }

  // Expose the API base URL for axios before the SPA imports.
  (window as any).__BAANDER_API_URL__ = apiServer;

  injectCSP(apiServer);

  // Import and start the React SPA
  await import('@/app/main.tsx');
}

bootstrap().catch((e) => {
  console.error('[bootstrap] Unhandled error:', e);
  import('@/app/main.tsx');
});
