/// <reference lib="webworker" />

declare const self: ServiceWorkerGlobalScope;

interface ClientAuth {
  accessToken: string | null;
  apiOrigin: string | null;
  nonce: string | null;
}

// Credentials belong to the window that supplied them, never to the worker itself.
const clientAuth = new Map<string, ClientAuth>();
const MAX_CLIENTS = 128;
const MAX_PENDING_PROOFS = 32;
let pendingProofs = 0;

self.addEventListener('install', () => { void self.skipWaiting(); });
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim().then(async () => {
    const clients = await self.clients.matchAll();
    clients.forEach(client => client.postMessage({ type: 'SW_REQUEST_TOKEN' }));
  }));
});

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  const source = event.source;
  if (!source || !('id' in source) || !('url' in source)) return;
  if (new URL(source.url).origin !== self.location.origin) return;
  const type = event.data?.type;
  if (type !== 'SW_SET_TOKEN' && type !== 'SW_SET_API_URL') return;
  const previous = clientAuth.get(source.id) ?? { accessToken: null, apiOrigin: null, nonce: null };
  const next = { ...previous };
  if (type === 'SW_SET_TOKEN') {
    next.accessToken = typeof event.data.token === 'string' && event.data.token ? event.data.token : null;
    if (next.accessToken !== previous.accessToken) next.nonce = null;
  } else {
    try {
      const url = new URL(event.data.apiUrl);
      if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) return;
      next.apiOrigin = url.origin;
    } catch { return; }
  }
  clientAuth.delete(source.id);
  if (clientAuth.size >= MAX_CLIENTS) clientAuth.delete(clientAuth.keys().next().value!);
  clientAuth.set(source.id, next);
});

function buildHtu(url: string): string {
  const parsed = new URL(url);
  return `${parsed.origin}${parsed.pathname}`;
}

function requestDpopProof(clientId: string, method: string, url: string, auth: ClientAuth): Promise<string | null> {
  if (pendingProofs >= MAX_PENDING_PROOFS) return Promise.resolve(null);
  pendingProofs++;
  return new Promise(resolve => {
    const channel = new MessageChannel();
    let finished = false;
    const finish = (proof: string | null) => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      channel.port1.onmessage = null;
      channel.port1.close();
      channel.port2.close();
      pendingProofs--;
      resolve(proof);
    };
    const timeout = setTimeout(() => finish(null), 3000);
    channel.port1.onmessageerror = () => finish(null);
    channel.port1.onmessage = event => {
      if (event.data?.type === 'SW_DPOP_PROOF' && typeof event.data.proof === 'string' &&
          clientAuth.get(clientId) === auth) {
        if (typeof event.data.nonce === 'string') auth.nonce = event.data.nonce;
        finish(event.data.proof);
      } else finish(null);
    };
    self.clients.get(clientId).then(client => {
      if (finished) return;
      if (!client || clientAuth.get(clientId) !== auth) { finish(null); return; }
      client.postMessage({
        type: 'SW_SIGN_DPOP', method, url, nonce: auth.nonce ?? undefined, token: auth.accessToken,
      }, [channel.port2]);
    }).catch(() => finish(null));
  });
}

self.addEventListener('fetch', (event: FetchEvent) => {
  const url = new URL(event.request.url);
  const auth = clientAuth.get(event.clientId);
  if (!auth?.accessToken || url.origin !== auth.apiOrigin) return;
  if (!url.pathname.startsWith('/api/stream/') && !url.pathname.startsWith('/api/images/')) return;
  if (!['GET', 'HEAD'].includes(event.request.method)) return;

  event.respondWith((async () => {
    const proof = await requestDpopProof(event.clientId, event.request.method, buildHtu(url.href), auth);
    if (!proof || clientAuth.get(event.clientId) !== auth) return fetch(event.request);
    const headers = new Headers(event.request.headers);
    headers.set('Authorization', `DPoP ${auth.accessToken}`);
    headers.set('DPoP', proof);
    const response = await fetch(event.request, {
      headers,
      mode: 'cors',
      credentials: 'omit',
      redirect: 'error',
    });
    if (clientAuth.get(event.clientId) === auth) {
      const nonce = response.headers.get('dpop-nonce');
      if (nonce) auth.nonce = nonce;
      if (response.status === 401) {
        const client = await self.clients.get(event.clientId);
        client?.postMessage({ type: 'SW_AUTH_EXPIRED' });
      }
    }
    return response;
  })());
});
