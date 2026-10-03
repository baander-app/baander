import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';
import { ensureFreshAccessToken, getAuthSession } from '@/shared/api-client/axios-instance';

/** Push credentials only while they still belong to this window's current session. */
export async function postTokenToWorker(token: string | null): Promise<void> {
  const registration = await navigator.serviceWorker?.getRegistration();
  if (!registration?.active || getAuthSnapshot().accessToken !== token) return;
  sendAuthState(registration.active);
}

interface WorkerAuthState {
  apiUrl: string;
  token: string | null;
  session: number;
  revision: number;
}

let authRevision = 0;

function sendAuthState(worker: ServiceWorker): WorkerAuthState {
  const state = {
    apiUrl: window.__BAANDER_API_URL__ || window.location.origin,
    token: getAuthSnapshot().accessToken,
    session: getAuthSession(),
    revision: ++authRevision,
  };
  worker.postMessage({ type: 'SW_SET_AUTH', ...state });
  return state;
}

/** Initialize both pieces of state, including after a worker restart. */
export async function initWorkerApiUrl(): Promise<void> {
  const registration = await navigator.serviceWorker?.getRegistration();
  if (registration?.active) sendAuthState(registration.active);
}

const ALLOWED_SW_PATHS = ['/api/stream/', '/api/images/'];

function isAllowedSignUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    const api = new URL(window.__BAANDER_API_URL__ || window.location.origin);
    return parsed.origin === api.origin && !parsed.username && !parsed.password &&
      ALLOWED_SW_PATHS.some(prefix => parsed.pathname.startsWith(prefix));
  } catch { return false; }
}

let listenerRegistered = false;

export function initServiceWorkerListener(): void {
  if (!('serviceWorker' in navigator) || listenerRegistered) return;
  listenerRegistered = true;
  navigator.serviceWorker.addEventListener('message', async event => {
    const replyPort = event.ports[0];
    const refreshing = event.data?.type === 'SW_REFRESH_AUTH';
    const failure = refreshing ? { type: 'SW_AUTH_REFRESHED', auth: null } : { type: 'SW_DPOP_PROOF', proof: null };
    try {
      const registration = await navigator.serviceWorker.getRegistration();
      if (!registration?.active || event.source !== registration.active) return;
      if (event.data?.type === 'SW_REQUEST_TOKEN') {
        sendAuthState(registration.active);
        return;
      }
      if ((!refreshing && event.data?.type !== 'SW_SIGN_DPOP') || !replyPort) return;

      const { method, url, nonce, token, session } = event.data;
      const keyPair = getDpopKeyPair();
      const { accessToken } = getAuthSnapshot();
      if (!keyPair || !accessToken || typeof token !== 'string' || !token ||
          session !== getAuthSession() || (!refreshing && token !== accessToken) ||
          !['GET', 'HEAD'].includes(method) || typeof url !== 'string' || !isAllowedSignUrl(url)) {
        replyPort.postMessage(failure);
        return;
      }
      if (refreshing) {
        await ensureFreshAccessToken(session, token);
        if (getAuthSession() !== session || getDpopKeyPair() !== keyPair || !getAuthSnapshot().accessToken) {
          replyPort.postMessage(failure);
          return;
        }
        // Global worker messages and port replies may arrive in either order.
        // Send the same revision through both; the worker keeps the newest state.
        const state = sendAuthState(registration.active);
        replyPort.postMessage({ type: 'SW_AUTH_REFRESHED', auth: state });
        return;
      }
      if (typeof nonce === 'string' && nonce) setDpopNonce(nonce);
      // Match the backend's existing scheme normalization after origin validation.
      const proof = await createDpopProof(keyPair, method, url.replace(/^http:/, 'https:'), {
        accessToken, nonce: getDpopNonce() ?? undefined,
      });
      // Signing may finish after logout, login, or token rotation.
      if (getAuthSnapshot().accessToken !== accessToken || getDpopKeyPair() !== keyPair || getAuthSession() !== session) {
        replyPort.postMessage({ type: 'SW_DPOP_PROOF', proof: null });
        return;
      }
      replyPort.postMessage({ type: 'SW_DPOP_PROOF', proof, nonce: getDpopNonce() });
    } catch {
      replyPort?.postMessage(failure);
    } finally {
      replyPort?.close();
    }
  });
}
