/// <reference lib="webworker" />

declare const self: ServiceWorkerGlobalScope;

/**
 * Service worker for authenticated audio streaming and image loading.
 * Intercepts fetch requests to /api/stream/* and /api/images/* and adds
 * DPoP proof and DPoP Authorization header.
 *
 * The private key is non-exportable and held only by the main thread.
 * This worker requests DPoP proof signatures via postMessage (SW_SIGN_DPOP),
 * and the main thread responds with the signed JWT (SW_DPOP_PROOF).
 */

let accessToken: string | null = null;
let dpopNonce: string | null = null;
let apiUrl: string | null = null;

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    self.clients.claim().then(() => {
      return self.clients.matchAll().then((clients) => {
        clients.forEach((client) => client.postMessage({ type: 'SW_REQUEST_TOKEN' }));
      });
    }),
  );
});

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  if (event.data?.type === 'SW_SET_TOKEN') {
    accessToken = event.data.token;
  }
  if (event.data?.type === 'SW_SET_API_URL') {
    apiUrl = event.data.apiUrl;
  }
});

function buildHtu(url: string): string {
  try {
    const baseUrl = apiUrl ?? self.location.origin;
    const parsed = new URL(url, baseUrl);
    return `https://${parsed.host}${parsed.pathname}`;
  } catch {
    return url;
  }
}

/**
 * Request a DPoP proof from the main thread.
 * Returns the signed JWT string, or null on failure/timeout.
 */
function requestDpopProof(method: string, url: string, token?: string): Promise<string | null> {
  return new Promise((resolve) => {
    const timeout = setTimeout(() => resolve(null), 3000);
    const nonce = dpopNonce;
    const channel = new MessageChannel();

    channel.port1.onmessage = (event) => {
      clearTimeout(timeout);
      if (event.data?.type === 'SW_DPOP_PROOF' && typeof event.data.proof === 'string') {
        if (event.data.nonce) {
          dpopNonce = event.data.nonce;
        }
        resolve(event.data.proof);
      } else {
        resolve(null);
      }
    };

    // Find any controlled client to send the signing request to
    self.clients.matchAll().then((clients) => {
      if (clients.length === 0) {
        clearTimeout(timeout);
        resolve(null);
        return;
      }
      // Use the first visible client
      const client = clients[0];
      client.postMessage(
        { type: 'SW_SIGN_DPOP', method, url, nonce: nonce ?? undefined },
        [channel.port2],
      );
    }).catch(() => {
      clearTimeout(timeout);
      resolve(null);
    });
  });
}

// --- Fetch interceptor ---

self.addEventListener('fetch', (event: FetchEvent) => {
  const url = new URL(event.request.url);

  if (!url.pathname.startsWith('/api/stream/') && !url.pathname.startsWith('/api/images/')) {
    return;
  }

  if (!accessToken) {
    return;
  }

  event.respondWith((async () => {
    const htu = buildHtu(url.toString());
    const proof = await requestDpopProof(event.request.method, htu, accessToken);

    if (!proof) {
      // No proof available — let the request through without auth.
      // The server will return 401 if auth is required.
      return fetch(event.request);
    }

    const headers = new Headers(event.request.headers);
    headers.set('Authorization', `DPoP ${accessToken}`);
    headers.set('DPoP', proof);

    const response = await fetch(event.request, { headers });

    // Extract DPoP-Nonce from response
    const nonce = response.headers.get('dpop-nonce');
    if (nonce) {
      dpopNonce = nonce;
    }

    // Notify main thread on 401 so it can trigger token refresh
    if (response.status === 401) {
      self.clients.matchAll().then((clients) => {
        clients.forEach((client) => client.postMessage({ type: 'SW_AUTH_EXPIRED' }));
      });
    }

    return response;
  })());
});
