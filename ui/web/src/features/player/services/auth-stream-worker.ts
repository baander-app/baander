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
// Deadlines for the window's answers. This file is a single-file build with no imports, so the values
// are declared here and pinned to src/shared/auth/refresh-budget.ts by tests/shared/auth/refresh-budget.test.ts.
const PROOF_DEADLINE_MS = 3000;
const REFRESH_DEADLINE_MS = 35000;
let pendingProofs = 0;
let pendingRefreshes = 0;
// One credential pull per window the browser restarted this worker under.
const pendingPulls = new Map<string, Promise<void>>();

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
  overflowed: boolean;
}

function requestDpopProof(clientId: string, method: string, url: string, auth: ClientAuth): Promise<DpopProofResult> {
  if (pendingProofs >= MAX_PENDING_PROOFS) return Promise.resolve({ proof: null, timedOut: false, overflowed: true });
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
      resolve({ proof, timedOut, overflowed: false });
    };
    const timeout = setTimeout(() => finish(null, true), PROOF_DEADLINE_MS);
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

type RefreshOutcome = 'refreshed' | 'failed' | 'overflowed';

function requestAuthRefresh(clientId: string, method: string, url: string, auth: ClientAuth): Promise<RefreshOutcome> {
  if (pendingRefreshes >= MAX_PENDING_REFRESHES) return Promise.resolve('overflowed');
  pendingRefreshes++;
  return new Promise(resolve => {
    const channel = new MessageChannel();
    let finished = false;
    const finish = (outcome: RefreshOutcome) => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      channel.port1.onmessage = null;
      channel.port1.onmessageerror = null;
      channel.port1.close();
      channel.port2.close();
      pendingRefreshes--;
      resolve(outcome);
    };
    // The window's whole refresh budget plus one message round trip.
    const timeout = setTimeout(() => finish('failed'), REFRESH_DEADLINE_MS);
    channel.port1.onmessageerror = () => finish('failed');
    channel.port1.onmessage = event => {
      const next = event.data?.type === 'SW_AUTH_REFRESHED' ? parseAuth(event.data.auth) : null;
      const current = clientAuth.get(clientId);
      if (!next || !current || next.session !== auth.session || next.apiOrigin !== auth.apiOrigin ||
          current.session !== auth.session || current.apiOrigin !== auth.apiOrigin || !current.accessToken) {
        finish('failed');
        return;
      }
      applyAuth(clientId, next);
      finish('refreshed');
    };
    self.clients.get(clientId).then(client => {
      if (finished) return;
      const current = clientAuth.get(clientId);
      if (!client || client.id !== clientId || !isSameOriginClient(client) || !current?.accessToken ||
          current.session !== auth.session || current.apiOrigin !== auth.apiOrigin) {
        finish('failed');
        return;
      }
      client.postMessage({ type: 'SW_REFRESH_AUTH', method, url, token: auth.accessToken, session: auth.session }, [channel.port2]);
    }).catch(() => finish('failed'));
  });
}

/**
 * The browser restarted this worker while the window kept playing. Ask that window, and only
 * that window, for the credentials it published before; one pull serves every request it has
 * pending. The reply is applied through the same revision order as a publication, so a newer
 * SW_SET_AUTH that arrives meanwhile is never replaced.
 */
function requestAuthPull(clientId: string): Promise<void> {
  const pending = pendingPulls.get(clientId);
  if (pending) return pending;
  const pull = new Promise<void>(resolve => {
    const channel = new MessageChannel();
    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      channel.port1.onmessage = null;
      channel.port1.onmessageerror = null;
      channel.port1.close();
      channel.port2.close();
      pendingPulls.delete(clientId);
      resolve();
    };
    const timeout = setTimeout(finish, PROOF_DEADLINE_MS);
    channel.port1.onmessageerror = finish;
    channel.port1.onmessage = event => {
      const next = event.data?.type === 'SW_AUTH_PULLED' ? parseAuth(event.data.auth) : null;
      if (next) applyAuth(clientId, next);
      finish();
    };
    self.clients.get(clientId).then(client => {
      if (finished) return;
      if (!client || client.id !== clientId || !isSameOriginClient(client)) {
        finish();
        return;
      }
      client.postMessage({ type: 'SW_PULL_AUTH' }, [channel.port2]);
    }).catch(finish);
  });
  pendingPulls.set(clientId, pull);
  return pull;
}

function authorizedFetch(request: Request, auth: ClientAuth, proof: string): Promise<Response> {
  const headers = new Headers(request.headers);
  headers.set('Authorization', `DPoP ${auth.accessToken}`);
  headers.set('DPoP', proof);
  return fetch(request, { headers, mode: 'cors', credentials: 'omit', redirect: 'error' });
}

/**
 * A media element cannot read the response that failed it. When the server refuses a track
 * stream because its encoders are busy, tell the requesting tab how long it asked to wait.
 */
function relayRetryAfter(clientId: string, url: URL, response: Response): void {
  if (response.status !== 503 || url.pathname !== '/api/stream/track') return;
  const header = response.headers.get('retry-after')?.trim() ?? '';
  if (!/^\d{1,4}$/.test(header)) return;
  self.clients.get(clientId).then(client => {
    if (client && client.id === clientId && isSameOriginClient(client)) {
      client.postMessage({ type: 'SW_STREAM_RETRY_AFTER', url: url.href, seconds: Number(header) });
    }
  }).catch(() => {});
}

function updateNonce(clientId: string, auth: ClientAuth, response: Response): void {
  if (clientAuth.get(clientId) !== auth) return;
  const nonce = response.headers.get('dpop-nonce');
  if (nonce) auth.nonce = nonce;
}

/**
 * The answer for a request that must not leave unauthenticated and cannot be signed right now:
 * the worker has no window to ask, or it holds too many pending answers. The tab waits a second
 * and asks again; for a track stream the relay turns the wait into a player retry.
 */
function serviceUnavailable(clientId: string, url: URL): Response {
  const response = new Response(null, { status: 503, headers: { 'Retry-After': '1' } });
  relayRetryAfter(clientId, url, response);
  return response;
}

function isCredentialedPath(pathname: string): boolean {
  return pathname.startsWith('/api/stream/') || pathname.startsWith('/api/images/');
}

async function serveAuthorized(event: FetchEvent, url: URL, auth: ClientAuth): Promise<Response> {
  const htu = buildHtu(url.href);
  let requestAuth = auth;
  const initialProof = await requestDpopProof(event.clientId, event.request.method, htu, requestAuth);
  if (initialProof.overflowed) return serviceUnavailable(event.clientId, url);
  let { proof } = initialProof;
  const latest = clientAuth.get(event.clientId);
  if (!initialProof.timedOut && latest?.accessToken && latest.accessToken !== auth.accessToken &&
      latest.session === auth.session && latest.apiOrigin === auth.apiOrigin) {
    // A shared refresh can rotate the token while the window signs this request.
    // Spend at most one extra proof attempt, always within the original session.
    requestAuth = latest;
    const secondProof = await requestDpopProof(event.clientId, event.request.method, htu, requestAuth);
    if (secondProof.overflowed) return serviceUnavailable(event.clientId, url);
    ({ proof } = secondProof);
  }
  if (!proof || clientAuth.get(event.clientId) !== requestAuth) return fetch(event.request);
  const response = await authorizedFetch(event.request, requestAuth, proof);
  updateNonce(event.clientId, requestAuth, response);
  if (response.status !== 401) {
    relayRetryAfter(event.clientId, url, response);
    return response;
  }
  const refresh = await requestAuthRefresh(event.clientId, event.request.method, htu, requestAuth);
  if (refresh === 'overflowed') {
    response.body?.cancel().catch(() => {});
    return serviceUnavailable(event.clientId, url);
  }
  if (refresh === 'failed') return response;
  const refreshed = clientAuth.get(event.clientId);
  if (!refreshed?.accessToken || refreshed.session !== auth.session || refreshed.apiOrigin !== auth.apiOrigin) return response;
  const retryProof = await requestDpopProof(event.clientId, event.request.method, htu, refreshed);
  if (retryProof.overflowed) {
    response.body?.cancel().catch(() => {});
    return serviceUnavailable(event.clientId, url);
  }
  if (!retryProof.proof || clientAuth.get(event.clientId) !== refreshed) return response;
  // Keep the first error readable until recovery has a valid, current proof.
  response.body?.cancel().catch(() => {});
  const retry = await authorizedFetch(event.request, refreshed, retryProof.proof);
  updateNonce(event.clientId, refreshed, retry);
  relayRetryAfter(event.clientId, url, retry);
  return retry;
}

/** A credentialed path from a window this worker holds no entry for: pull before deciding. */
async function serveAfterPull(event: FetchEvent, url: URL): Promise<Response> {
  await requestAuthPull(event.clientId);
  // The newest entry wins, whether the pull reply or a publication in flight armed it.
  const auth = clientAuth.get(event.clientId);
  if (!auth) return serviceUnavailable(event.clientId, url);
  if (url.origin !== auth.apiOrigin || !auth.accessToken) return fetch(event.request);
  return serveAuthorized(event, url, auth);
}

self.addEventListener('fetch', (event: FetchEvent) => {
  const url = new URL(event.request.url);
  if (!isCredentialedPath(url.pathname) || !['GET', 'HEAD'].includes(event.request.method)) return;
  const auth = clientAuth.get(event.clientId);
  if (auth) {
    if (!auth.accessToken || url.origin !== auth.apiOrigin) return;
    event.respondWith(serveAuthorized(event, url, auth));
    return;
  }
  // Only a window the worker can name may supply credentials; a navigation has no window yet.
  if (!event.clientId || event.request.mode === 'navigate') return;
  event.respondWith(serveAfterPull(event, url));
});
