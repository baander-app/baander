/// <reference lib="webworker" />

declare const self: ServiceWorkerGlobalScope;

interface ClientAuth {
  accessToken: string | null;
  apiOrigin: string;
  session: number;
  revision: number;
  nonce: string | null;
}

// Credentials belong to the window that supplied them, never to the worker itself.
const clientAuth = new Map<string, ClientAuth>();
const MAX_CLIENTS = 128;
const MAX_PENDING_PROOFS = 32;
const MAX_PENDING_REFRESHES = 32;
let pendingProofs = 0;
let pendingRefreshes = 0;

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim().then(async () => {
    const clients = await self.clients.matchAll();
    clients.forEach(client => client.postMessage({ type: 'SW_REQUEST_TOKEN' }));
  }));
});

function parseAuth(snapshot: unknown): ClientAuth | null {
  if (!snapshot || typeof snapshot !== 'object') return null;
  const { apiUrl, token, session, revision } = snapshot as Record<string, unknown>;
  if (typeof apiUrl !== 'string' || (token !== null && (typeof token !== 'string' || !token)) ||
      typeof session !== 'number' || !Number.isSafeInteger(session) || session < 0 ||
      typeof revision !== 'number' || !Number.isSafeInteger(revision) || revision < 0 ||
      (token !== null && session === 0)) return null;
  try {
    const url = new URL(apiUrl);
    if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) return null;
    return { accessToken: token, apiOrigin: url.origin, session, revision, nonce: null };
  } catch { return null; }
}

function applyAuth(clientId: string, next: ClientAuth): void {
  const previous = clientAuth.get(clientId);
  if (previous && next.revision <= previous.revision) return;
  if (previous && previous.accessToken === next.accessToken && previous.session === next.session &&
      previous.apiOrigin === next.apiOrigin) {
    // Publications of the same credentials must not invalidate an in-flight proof.
    previous.revision = next.revision;
    return;
  }
  if (!previous && clientAuth.size >= MAX_CLIENTS) clientAuth.delete(clientAuth.keys().next().value!);
  clientAuth.set(clientId, next);
}

function isSameOriginClient(client: Client): boolean {
  try { return new URL(client.url).origin === self.location.origin; } catch { return false; }
}

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  const source = event.source;
  if (!source || !('id' in source) || !('url' in source) || !isSameOriginClient(source)) return;
  if (event.data?.type !== 'SW_SET_AUTH') return;
  const next = parseAuth(event.data);
  if (next) applyAuth(source.id, next);
});

function buildHtu(url: string): string {
  const parsed = new URL(url);
  return `${parsed.origin}${parsed.pathname}`;
}

interface DpopProofResult {
  proof: string | null;
  timedOut: boolean;
}

function requestDpopProof(clientId: string, method: string, url: string, auth: ClientAuth): Promise<DpopProofResult> {
  if (pendingProofs >= MAX_PENDING_PROOFS) return Promise.resolve({ proof: null, timedOut: false });
  pendingProofs++;
  return new Promise(resolve => {
    const channel = new MessageChannel();
    let finished = false;
    const finish = (proof: string | null, timedOut = false) => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      channel.port1.onmessage = null;
      channel.port1.onmessageerror = null;
      channel.port1.close();
      channel.port2.close();
      pendingProofs--;
      resolve({ proof, timedOut });
    };
    const timeout = setTimeout(() => finish(null, true), 3000);
    channel.port1.onmessageerror = () => finish(null);
    channel.port1.onmessage = event => {
      if (event.data?.type === 'SW_DPOP_PROOF' && typeof event.data.proof === 'string' && event.data.proof &&
          clientAuth.get(clientId) === auth) {
        if (typeof event.data.nonce === 'string') auth.nonce = event.data.nonce;
        finish(event.data.proof);
      } else finish(null);
    };
    self.clients.get(clientId).then(client => {
      if (finished) return;
      if (!client || client.id !== clientId || !isSameOriginClient(client) || clientAuth.get(clientId) !== auth) {
        finish(null); return;
      }
      client.postMessage({
        type: 'SW_SIGN_DPOP', method, url, nonce: auth.nonce ?? undefined,
        token: auth.accessToken, session: auth.session,
      }, [channel.port2]);
    }).catch(() => finish(null));
  });
}

function requestAuthRefresh(clientId: string, method: string, url: string, auth: ClientAuth): Promise<boolean> {
  if (pendingRefreshes >= MAX_PENDING_REFRESHES) return Promise.resolve(false);
  pendingRefreshes++;
  return new Promise(resolve => {
    const channel = new MessageChannel();
    let finished = false;
    const finish = (refreshed: boolean) => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      channel.port1.onmessage = null;
      channel.port1.onmessageerror = null;
      channel.port1.close();
      channel.port2.close();
      pendingRefreshes--;
      resolve(refreshed);
    };
    // The window can perform two bounded 15s refresh attempts.
    const timeout = setTimeout(() => finish(false), 35000);
    channel.port1.onmessageerror = () => finish(false);
    channel.port1.onmessage = event => {
      const next = event.data?.type === 'SW_AUTH_REFRESHED' ? parseAuth(event.data.auth) : null;
      const current = clientAuth.get(clientId);
      if (!next || !current || next.session !== auth.session || next.apiOrigin !== auth.apiOrigin ||
          current.session !== auth.session || current.apiOrigin !== auth.apiOrigin || !current.accessToken) {
        finish(false); return;
      }
      applyAuth(clientId, next);
      finish(true);
    };
    self.clients.get(clientId).then(client => {
      if (finished) return;
      const current = clientAuth.get(clientId);
      if (!client || client.id !== clientId || !isSameOriginClient(client) || !current?.accessToken ||
          current.session !== auth.session || current.apiOrigin !== auth.apiOrigin) {
        finish(false); return;
      }
      client.postMessage({ type: 'SW_REFRESH_AUTH', method, url, token: auth.accessToken, session: auth.session }, [channel.port2]);
    }).catch(() => finish(false));
  });
}

function authorizedFetch(request: Request, auth: ClientAuth, proof: string): Promise<Response> {
  const headers = new Headers(request.headers);
  headers.set('Authorization', `DPoP ${auth.accessToken}`);
  headers.set('DPoP', proof);
  return fetch(request, { headers, mode: 'cors', credentials: 'omit', redirect: 'error' });
}

function updateNonce(clientId: string, auth: ClientAuth, response: Response): void {
  if (clientAuth.get(clientId) !== auth) return;
  const nonce = response.headers.get('dpop-nonce');
  if (nonce) auth.nonce = nonce;
}

self.addEventListener('fetch', (event: FetchEvent) => {
  const url = new URL(event.request.url);
  const auth = clientAuth.get(event.clientId);
  if (!auth?.accessToken || url.origin !== auth.apiOrigin) return;
  if (!url.pathname.startsWith('/api/stream/') && !url.pathname.startsWith('/api/images/')) return;
  if (!['GET', 'HEAD'].includes(event.request.method)) return;

  event.respondWith((async () => {
    const htu = buildHtu(url.href);
    let requestAuth = auth;
    const initialProof = await requestDpopProof(event.clientId, event.request.method, htu, requestAuth);
    let { proof } = initialProof;
    const latest = clientAuth.get(event.clientId);
    if (!initialProof.timedOut && latest?.accessToken && latest.accessToken !== auth.accessToken &&
        latest.session === auth.session && latest.apiOrigin === auth.apiOrigin) {
      // A shared refresh can rotate the token while the window signs this request.
      // Spend at most one extra proof attempt, always within the original session.
      requestAuth = latest;
      ({ proof } = await requestDpopProof(event.clientId, event.request.method, htu, requestAuth));
    }
    if (!proof || clientAuth.get(event.clientId) !== requestAuth) return fetch(event.request);
    const response = await authorizedFetch(event.request, requestAuth, proof);
    updateNonce(event.clientId, requestAuth, response);
    if (response.status !== 401) return response;
    if (!await requestAuthRefresh(event.clientId, event.request.method, htu, requestAuth)) return response;
    const refreshed = clientAuth.get(event.clientId);
    if (!refreshed?.accessToken || refreshed.session !== auth.session || refreshed.apiOrigin !== auth.apiOrigin) return response;
    const { proof: retryProof } = await requestDpopProof(event.clientId, event.request.method, htu, refreshed);
    if (!retryProof || clientAuth.get(event.clientId) !== refreshed) return response;
    // Keep the first error readable until recovery has a valid, current proof.
    response.body?.cancel().catch(() => {});
    const retry = await authorizedFetch(event.request, refreshed, retryProof);
    updateNonce(event.clientId, refreshed, retry);
    return retry;
  })());
});
