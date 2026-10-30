import Axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';
import { REFRESH_ATTEMPT_TIMEOUT_MS, REFRESH_BUDGET_MS, outageWaitMs } from '@/shared/auth/refresh-budget';
import { retryAfterSeconds } from '@/features/auth/lib/retry-after';
import { coordinateRefresh } from '@/features/auth/services/auth-coordination';
import { assertCurrentOwnership, captureOwnership, isCurrentOwnership, ownershipForSignal, waitForOwnershipAuthority, type OwnershipStamp } from '@/shared/live/ownership';

const getAuthStore = () => useAuthStore.getState();

interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
  _dpopRetryCount?: number;
  _authToken?: string | null;
  _authSession?: number;
  _ownership?: OwnershipStamp;
}

const MAX_DPOP_NONCE_RETRIES = 1;

// Scalar metadata survives Axios config cloning without retaining crypto objects.
const authSessions = new WeakMap<object, number>();
let nextAuthSession = 1;

export function getAuthSession(keyPair = getDpopKeyPair()): number {
  if (!keyPair) return 0;
  let session = authSessions.get(keyPair);
  if (session === undefined) {
    session = nextAuthSession++;
    authSessions.set(keyPair, session);
  }
  return session;
}

export const AXIOS_INSTANCE = Axios.create({
  baseURL: window.__BAANDER_API_URL__,
  withCredentials: false,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

/** The origin the API is served from: the configured API URL, or the page's own origin. */
export function apiOrigin(): string {
  return new URL(window.__BAANDER_API_URL__ || window.location.origin).origin;
}

/**
 * Build the htu (HTTP URI) for a DPoP proof.
 * Preserves the request origin and path, excluding query and fragment.
 */
export function buildHtu(url: string): string {
  try {
    const parsed = new URL(url, apiOrigin());
    return `${parsed.origin}${parsed.pathname}`;
  } catch {
    return url;
  }
}

function isApiRequest(config: AxiosRequestConfig): boolean {
  try {
    const target = new URL(AXIOS_INSTANCE.getUri(config), window.location.href);
    return target.origin === apiOrigin() && !target.username && !target.password;
  } catch {
    return false;
  }
}

const credentialControlPaths = new Set([
  '/api/auth/login',
  '/api/auth/login/passkey',
  '/api/auth/passkey/authenticate/options',
  '/api/auth/passkey/authenticate',
  '/api/auth/register',
  '/api/auth/logout',
  '/api/auth/refresh',
  '/api/auth/password/reset-request',
  '/api/auth/password/reset',
  '/api/auth/email/verify',
]);

function isAuthorityControlRequest(config: AxiosRequestConfig): boolean {
  const path = new URL(AXIOS_INSTANCE.getUri(config), apiOrigin()).pathname;
  const method = config.method?.toUpperCase();
  return (method === 'GET' && path === '/api/live/recovery')
    || (method === 'POST' && (path === '/api/ws/tickets' || credentialControlPaths.has(path)));
}

// --- Request interceptor: attach DPoP proof + sender-constrained token ---
AXIOS_INSTANCE.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const customConfig = config as CustomAxiosRequestConfig;
  config.headers.delete('Authorization');
  config.headers.delete('DPoP');
  if (!isApiRequest(config)) return config;
  const signal = config.signal;
  customConfig._ownership ??= (signal && ownershipForSignal(signal)) || captureOwnership();
  assertCurrentOwnership(customConfig._ownership);
  if (!isAuthorityControlRequest(config)) {
    await waitForOwnershipAuthority(signal);
    assertCurrentOwnership(customConfig._ownership);
  }
  const {accessToken} = getAuthStore();
  const keyPair = getDpopKeyPair();
  const session = getAuthSession(keyPair);
  if (customConfig._authSession !== undefined && customConfig._authSession !== session) {
    throw new Axios.CanceledError('Authentication changed before request retry');
  }
  customConfig._authSession = session;
  customConfig._authToken = accessToken;

  if (keyPair) {
    // Attach DPoP proof whenever a key pair exists (including login).
    // Only attach the Authorization header when _skipAuth is false.
    if (!customConfig._skipAuth && accessToken) {
      config.headers.Authorization = `DPoP ${accessToken}`;
    }

    const htu = buildHtu(AXIOS_INSTANCE.getUri(config));
    const proof = await createDpopProof(keyPair, config.method ?? 'GET', htu, {
      accessToken: !customConfig._skipAuth ? (accessToken ?? undefined) : undefined,
      nonce: getDpopNonce() ?? undefined,
    });
    if (getAuthStore().accessToken !== accessToken || getDpopKeyPair() !== keyPair) {
      throw new Axios.CanceledError('Authentication changed while signing');
    }
    assertCurrentOwnership(customConfig._ownership);
    config.headers.DPoP = proof;
  }

  return config;
});

// --- Response interceptor: extract DPoP-Nonce from responses ---
AXIOS_INSTANCE.interceptors.response.use((response) => {
  if (!isApiRequest(response.config)) return response;
  const config = response.config as CustomAxiosRequestConfig;
  if (config._ownership) assertCurrentOwnership(config._ownership);
  if (config._authSession !== getAuthSession()) {
    throw new Axios.CanceledError('Authentication changed before response received');
  }
  const nonce = response.headers?.['dpop-nonce'];
  if (typeof nonce === 'string' && nonce !== '') {
    setDpopNonce(nonce);
  }
  return response;
});

// --- Token refresh: queue concurrent 401s, retry after refresh ---
/**
 * Only the refresh endpoint refusing the refresh token ends the session: a 401, or an OAuth
 * 400 invalid_grant. A network error, a timeout, a 5xx (a restarting server or a store outage)
 * and any other 400, such as a nonce challenge still answered after the one nonce retry, keep
 * the user signed in and fail just the request.
 */
export function isRefreshRejected(error: unknown): boolean {
  if (!Axios.isAxiosError(error)) return false;
  const status = error.response?.status;
  return status === 401 || (status === 400 && error.response?.data?.error === 'invalid_grant');
}

/** The answer never arrived: a timeout or a network error, as opposed to a cancelled request. */
function isTransportFailure(error: unknown): boolean {
  return Axios.isAxiosError(error) && !error.response && !Axios.isCancel(error);
}

/** The nonce a `use_dpop_nonce` challenge asks the next proof to carry, or null for any other answer. */
export function challengedNonce(error: unknown): string | null {
  if (!Axios.isAxiosError(error) || error.response?.status !== 400) return null;
  if (error.response.data?.error !== 'use_dpop_nonce') return null;
  const nonce = error.response.headers?.['dpop-nonce'];
  return typeof nonce === 'string' && nonce !== '' ? nonce : null;
}

/**
 * Milliseconds to wait out a 503 before one more attempt, from its `Retry-After` with jitter, or
 * null for any other answer.
 */
export function outageWaitFor(error: unknown): number | null {
  const retryAfter = retryAfterSeconds(error, { status: 503 });
  return retryAfter === null ? null : outageWaitMs(retryAfter);
}

export function wait(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}

let refreshInFlight: { session: number; promise: Promise<void> } | null = null;

/**
 * One refresh has one wall-clock budget (see `@/shared/auth/refresh-budget`), shared by the wait for
 * the origin's auth lock and the attempts. Under the lock the tab first adopts a rotation another tab
 * already made; otherwise it presents its refresh token. A transport failure, a nonce challenge and a
 * 503 each buy one more attempt; the remaining budget is checked before every attempt and bounds it;
 * every attempt signs a fresh proof with the same key. The lock is held until the rotation's write has
 * completed, which `setTokens` resolves on.
 */
async function refreshSession(session: number, failedAccessToken: string): Promise<void> {
  const ownership = captureOwnership();
  const deadline = Date.now() + REFRESH_BUDGET_MS;
  await coordinateRefresh({
    budgetMs: REFRESH_BUDGET_MS,
    // A rotation adopted from a `rotated` event while waiting for the lock also leaves nothing to refresh.
    adopt: async () => {
      assertCurrentOwnership(ownership);
      const adopted = await getAuthStore().adoptStoredAuth();
      assertCurrentOwnership(ownership);
      return adopted || getAuthStore().accessToken !== failedAccessToken;
    },
    refresh: () => {
      assertCurrentOwnership(ownership);
      return refreshWithServer(session, deadline);
    },
  });
}

async function refreshWithServer(session: number, deadline: number): Promise<void> {
  const ownership = captureOwnership();
  const {accessToken, refreshToken} = getAuthStore();
  const keyPair = getDpopKeyPair();
  const isCurrentSession = () => isCurrentOwnership(ownership) && getAuthSession() === session && getAuthStore().refreshToken === refreshToken &&
    getAuthStore().accessToken === accessToken && getDpopKeyPair() === keyPair;
  try {
    if (!refreshToken || !keyPair) throw new Error('No refresh credentials');
    const refreshUrl = new URL('/api/auth/refresh', window.__BAANDER_API_URL__ || window.location.origin).href;
    const htu = buildHtu(refreshUrl);
    const retried = { transport: false, nonce: false, outage: false };
    let lastError: unknown = new Error('Refresh budget exhausted');
    for (;;) {
      const remaining = deadline - Date.now();
      if (remaining <= 0) throw lastError;
      const proof = await createDpopProof(keyPair, 'POST', htu, {
        nonce: getDpopNonce() ?? undefined,
      });
      if (!isCurrentSession()) throw new Axios.CanceledError('Authentication changed during refresh');
      try {
        const response = await Axios.post(refreshUrl, {refreshToken}, {
          headers: {DPoP: proof},
          timeout: Math.min(REFRESH_ATTEMPT_TIMEOUT_MS, remaining),
        });
        if (!isCurrentSession()) throw new Axios.CanceledError('Authentication changed during refresh');
        const data = response.data?.data ?? response.data;
        if (typeof data?.accessToken !== 'string' || !data.accessToken ||
            typeof data?.refreshToken !== 'string' || !data.refreshToken) {
          throw new Error('Refresh returned invalid tokens');
        }
        const nonce = response.headers['dpop-nonce'];
        if (typeof nonce === 'string' && nonce) setDpopNonce(nonce);
        await getAuthStore().setTokens(data.accessToken, data.refreshToken);
        return;
      } catch (error) {
        if (!isCurrentSession()) throw error;
        lastError = error;
        const nonce = challengedNonce(error);
        if (nonce !== null && !retried.nonce) {
          retried.nonce = true;
          setDpopNonce(nonce);
          continue;
        }
        if (isTransportFailure(error) && !retried.transport) {
          retried.transport = true;
          continue;
        }
        const outageWait = outageWaitFor(error);
        if (outageWait !== null && !retried.outage) {
          retried.outage = true;
          // A wait that ends past the budget would start no attempt; fail now instead.
          if (Date.now() + outageWait >= deadline) throw error;
          await wait(outageWait);
          if (!isCurrentSession()) throw new Axios.CanceledError('Authentication changed during refresh');
          continue;
        }
        throw error;
      }
    }
  } catch (error) {
    if (isCurrentSession() && isRefreshRejected(error)) {
      getAuthStore().clearAuth();
      window.location.href = '/login';
    }
    throw error;
  }
}

/** Share one refresh between API requests and native media in this window. */
export async function ensureFreshAccessToken(expectedSession: number, failedAccessToken: string): Promise<string> {
  const ownership = captureOwnership();
  const assertCurrent = () => {
    assertCurrentOwnership(ownership);
    if (expectedSession !== getAuthSession() || !expectedSession) {
      throw new Axios.CanceledError('Authentication changed before refresh');
    }
    const { accessToken, refreshToken } = getAuthStore();
    if (!accessToken || !refreshToken || !getDpopKeyPair() || !failedAccessToken) {
      throw new Axios.CanceledError('No refresh credentials');
    }
    return accessToken;
  };
  const currentToken = assertCurrent();
  // A late 401 for the old token reuses the already rotated session.
  if (failedAccessToken !== currentToken) return currentToken;
  if (!refreshInFlight || refreshInFlight.session !== expectedSession) {
    const pending = { session: expectedSession, promise: refreshSession(expectedSession, failedAccessToken) };
    refreshInFlight = pending;
    pending.promise = pending.promise.finally(() => {
      if (refreshInFlight === pending) refreshInFlight = null;
    });
  }
  await refreshInFlight.promise;
  const freshToken = assertCurrent();
  if (freshToken === failedAccessToken) {
    throw new Axios.CanceledError('Refresh did not replace the access token');
  }
  return freshToken;
}

AXIOS_INSTANCE.interceptors.response.use(undefined, async (error) => {
  const originalRequest = error.config as CustomAxiosRequestConfig | undefined;
  if (!originalRequest || !isApiRequest(originalRequest)) return Promise.reject(error);
  if (originalRequest._ownership) assertCurrentOwnership(originalRequest._ownership);
  const session = getAuthSession();
  if (originalRequest._authSession !== session) {
    return Promise.reject(new Axios.CanceledError('Authentication changed before response received'));
  }
  if (originalRequest._authToken !== getAuthStore().accessToken && !getAuthStore().accessToken) {
    return Promise.reject(error);
  }

  if (error.response?.status !== 401 || originalRequest._skipAuth || originalRequest._didRetry) {
    // Handle use_dpop_nonce error from token endpoint: retry with nonce
    const nonce = challengedNonce(error);
    if (
      nonce !== null &&
      !originalRequest._didRetry &&
      (originalRequest._dpopRetryCount ?? 0) < MAX_DPOP_NONCE_RETRIES
    ) {
      setDpopNonce(nonce);
      originalRequest._dpopRetryCount = (originalRequest._dpopRetryCount ?? 0) + 1;
      return AXIOS_INSTANCE(originalRequest);
    }

    return Promise.reject(error);
  }

  // Every participant spends its one refresh retry, including queued requests.
  originalRequest._didRetry = true;
  await ensureFreshAccessToken(session, originalRequest._authToken ?? '');
  return AXIOS_INSTANCE(originalRequest);
});

export function customInstance<T>(url: string, options: RequestInit): Promise<T>;
export function customInstance(url: string, options: RequestInit): Promise<unknown> {
  const {body, signal, headers, ...rest} = options;
  return AXIOS_INSTANCE<unknown>({
    url,
    method: rest.method as AxiosRequestConfig['method'],
    data: body as AxiosRequestConfig['data'],
    headers: headers as AxiosRequestConfig['headers'],
    signal: signal as AxiosRequestConfig['signal'],
  }).then((response) => response.status === 204 ? undefined : response.data);
}

export type ErrorType<Error> = AxiosError<Error>

export type BodyType<BodyData> = BodyData
