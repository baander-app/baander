import { getServerUrl } from '../shared/config-store';

async function injectCSP() {
  // Remove existing CSP tag if present
  const existingCSP = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
  if (existingCSP) {
    existingCSP.remove();
  }

  const apiServer = await getServerUrl();

  // Create new CSP meta tag with dynamic backend URL
  const cspTag = document.createElement('meta');
  cspTag.httpEquiv = 'Content-Security-Policy';
  cspTag.content = `
    default-src 'self';
    script-src 'self' 'unsafe-eval' blob: http://localhost:5173 ws://localhost:5173;
    style-src 'self' 'unsafe-inline' http://localhost:5173;
    img-src 'self' data: http://localhost:5173 ${apiServer};
    connect-src 'self' blob: ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://localhost:5173 ${apiServer};
    font-src 'self' data: http://localhost:5173;
    media-src 'self' blob: http://localhost:5173 ${apiServer};
    object-src 'none';
    frame-src 'none';
  `;

  // Insert it into the head before any other elements
  document.head.insertBefore(cspTag, document.head.firstChild);
}

async function bootstrap() {
  await injectCSP();

  // Now import and start the real app entry
  await import('@/app/index.tsx');
}

bootstrap().catch((e) => {
  console.error('[bootstrap] Unhandled error:', e);
  import('@/app/index.tsx');
});
