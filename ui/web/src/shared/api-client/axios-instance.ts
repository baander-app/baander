import Axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';

const getAuthStore = () => useAuthStore.getState();

interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
  _dpopRetryCount?: number;
  _authToken?: string | null;
  _authSession?: number;
}

const MAX_DPOP_NONCE_RETRIES = 1;

// Scalar metadata survives Axios config cloning without retaining crypto objects.
const authSessions = new WeakMap<object, number>();
let nextAuthSession = 1;

function getAuthSession(keyPair = getDpopKeyPair()): number {
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

/**
 * Build the htu (HTTP URI) for a DPoP proof.
 * Strips query and fragment, normalizes to https scheme.
 */
function buildHtu(url: string): string {
  try {
    const baseUrl = window.__BAANDER_API_URL__ || window.location.origin;
    const parsed = new URL(url, baseUrl);
    // Normalize http/https to https per RFC 9449 §4.3
    return `https://${parsed.host}${parsed.pathname}`;
  } catch {
    return url;
  }
}

function isApiRequest(config: AxiosRequestConfig): boolean {
  try {
    const api = new URL(window.__BAANDER_API_URL__ || window.location.origin);
    const target = new URL(AXIOS_INSTANCE.getUri(config), window.location.origin);
    return target.origin === api.origin && !target.username && !target.password;
  } catch {
    return false;
  }
}

// --- Request interceptor: attach DPoP proof + sender-constrained token ---
AXIOS_INSTANCE.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const customConfig = config as CustomAxiosRequestConfig;
  config.headers.delete('Authorization');
  config.headers.delete('DPoP');
  if (!isApiRequest(config)) return config;
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
    config.headers.DPoP = proof;
  }

  return config;
});

// --- Response interceptor: extract DPoP-Nonce from responses ---
AXIOS_INSTANCE.interceptors.response.use((response) => {
  if (!isApiRequest(response.config)) return response;
  const config = response.config as CustomAxiosRequestConfig;
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
let refreshInFlight: { session: number; promise: Promise<void> } | null = null;

async function refreshSession(session: number): Promise<void> {
  const {accessToken, refreshToken} = getAuthStore();
  const keyPair = getDpopKeyPair();
  const isCurrentSession = () => getAuthSession() === session && getAuthStore().refreshToken === refreshToken &&
    getAuthStore().accessToken === accessToken && getDpopKeyPair() === keyPair;
  try {
    if (!refreshToken || !keyPair) throw new Error('No refresh credentials');
    const refreshUrl = new URL('/api/auth/refresh', window.__BAANDER_API_URL__ || window.location.origin).href;
    for (let attempt = 0; attempt <= MAX_DPOP_NONCE_RETRIES; attempt++) {
      const proof = await createDpopProof(keyPair, 'POST', buildHtu(refreshUrl), {
        nonce: getDpopNonce() ?? undefined,
      });
      if (!isCurrentSession()) throw new Axios.CanceledError('Authentication changed during refresh');
      try {
        const response = await Axios.post(refreshUrl, {refreshToken}, {
          headers: {DPoP: proof}, timeout: 15000,
        });
        if (!isCurrentSession()) throw new Axios.CanceledError('Authentication changed during refresh');
        const data = response.data?.data ?? response.data;
        if (typeof data?.accessToken !== 'string' || !data.accessToken ||
            typeof data?.refreshToken !== 'string' || !data.refreshToken) {
          throw new Error('Refresh returned invalid tokens');
        }
        const nonce = response.headers['dpop-nonce'];
        if (typeof nonce === 'string' && nonce) setDpopNonce(nonce);
        getAuthStore().setTokens(data.accessToken, data.refreshToken);
        return;
      } catch (error) {
        if (!isCurrentSession()) throw error;
        const nonce = Axios.isAxiosError(error) ? error.response?.headers?.['dpop-nonce'] : undefined;
        if (attempt < MAX_DPOP_NONCE_RETRIES && Axios.isAxiosError(error) &&
            error.response?.status === 400 && error.response.data?.error === 'use_dpop_nonce' &&
            typeof nonce === 'string' && nonce) {
          setDpopNonce(nonce);
          continue;
        }
        throw error;
      }
    }
  } catch (error) {
    if (isCurrentSession()) {
      getAuthStore().clearAuth();
      window.location.href = '/login';
    }
    throw error;
  }
}

AXIOS_INSTANCE.interceptors.response.use(undefined, async (error) => {
  const originalRequest = error.config as CustomAxiosRequestConfig | undefined;
  if (!originalRequest || !isApiRequest(originalRequest)) return Promise.reject(error);
  const session = getAuthSession();
  if (originalRequest._authSession !== session) {
    return Promise.reject(new Axios.CanceledError('Authentication changed before response received'));
  }
  if (originalRequest._authToken !== getAuthStore().accessToken && !getAuthStore().accessToken) {
    return Promise.reject(error);
  }

  if (error.response?.status !== 401 || originalRequest._skipAuth || originalRequest._didRetry) {
    // Handle use_dpop_nonce error from token endpoint: retry with nonce
    if (
      error.response?.status === 400 &&
      error.response?.data?.error === 'use_dpop_nonce' &&
      !originalRequest._didRetry &&
      (originalRequest._dpopRetryCount ?? 0) < MAX_DPOP_NONCE_RETRIES
    ) {
      const nonce = error.response?.headers?.['dpop-nonce'] ?? error.response?.headers?.['DPoP-Nonce'];
      if (typeof nonce === 'string' && nonce !== '') {
        setDpopNonce(nonce);
        originalRequest._dpopRetryCount = (originalRequest._dpopRetryCount ?? 0) + 1;
        return AXIOS_INSTANCE(originalRequest);
      }
    }

    return Promise.reject(error);
  }

  // Every participant spends its one refresh retry, including queued requests.
  originalRequest._didRetry = true;
  // A late 401 for the old token must reuse the already rotated session.
  if (originalRequest._authToken === getAuthStore().accessToken) {
    if (!refreshInFlight || refreshInFlight.session !== session) {
      const pending = { session, promise: refreshSession(session) };
      refreshInFlight = pending;
      pending.promise = pending.promise.finally(() => {
        if (refreshInFlight === pending) refreshInFlight = null;
      });
    }
    await refreshInFlight.promise;
  }
  if (originalRequest._authSession !== getAuthSession()) {
    throw new Axios.CanceledError('Authentication changed before request retry');
  }
  return AXIOS_INSTANCE(originalRequest);
});

export const customInstance = <T>(
  url: string,
  options: RequestInit,
): Promise<T> => {
  const {body, signal, headers, ...rest} = options;
  return AXIOS_INSTANCE({
    url,
    method: rest.method as AxiosRequestConfig['method'],
    data: body as AxiosRequestConfig['data'],
    headers: headers as AxiosRequestConfig['headers'],
    signal: signal as AxiosRequestConfig['signal'],
  }).then((response) => ({
    ...response.data,
    status: response.status,
    headers: response.headers,
  })) as Promise<T>;
};

export type ErrorType<Error> = AxiosError<Error>

export type BodyType<BodyData> = BodyData
