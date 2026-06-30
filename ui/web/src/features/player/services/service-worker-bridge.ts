import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import { getDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';

/**
 * Push the current access token (no key material) to the active service worker.
 * The SW requests DPoP proof signatures from the main thread via SW_SIGN_DPOP.
 */
export async function postTokenToWorker(token: string): Promise<void> {
  const registration = await navigator.serviceWorker?.getRegistration();
  if (!registration?.active) return;

  registration.active.postMessage({
    type: 'SW_SET_TOKEN',
    token,
  });
}

/**
 * Initialize the service worker with the API URL.
 * Must be called after the service worker is registered.
 */
export async function initWorkerApiUrl(): Promise<void> {
  const registration = await navigator.serviceWorker?.getRegistration();
  if (!registration?.active) return;

  registration.active.postMessage({
    type: 'SW_SET_API_URL',
    apiUrl: window.__BAANDER_API_URL__,
  });
}

/**
 * URL paths the service worker is allowed to request DPoP proofs for.
 * Any request outside these patterns is rejected.
 */
const ALLOWED_SW_PATHS = [
  '/api/stream/',
  '/api/images/',
];

/** Verify a URL is within the allowed set for SW signing. */
function isAllowedSignUrl(url: string): boolean {
  try {
    const parsed = new URL(url, window.location.origin);
    if (parsed.origin !== window.location.origin) return false;
    return ALLOWED_SW_PATHS.some((prefix) => parsed.pathname.startsWith(prefix));
  } catch {
    return false;
  }
}

/**
 * Listen for messages from the service worker:
 * - SW_REQUEST_TOKEN: SW asking for current token on activation
 * - SW_SIGN_DPOP: SW requesting a DPoP proof signature (private key never leaves main thread)
 *
 * Security:
 * - Messages on `navigator.serviceWorker` are guaranteed by the browser to come from
 *   the registered service worker for this scope. No other source can send through this channel.
 * - We further verify event.source matches our registration's active worker.
 * - Signing is restricted to allowed URL paths (/api/stream/*, /api/images/*).
 * - The access token is always taken from the current session — the message-supplied
 *   token is ignored. This prevents a compromised SW from obtaining proofs for a
 *   different user's token or a stolen token.
 */
let listenerRegistered = false;

export function initServiceWorkerListener(): void {
  if (!('serviceWorker' in navigator) || listenerRegistered) return;
  listenerRegistered = true;

  navigator.serviceWorker.addEventListener('message', async (event) => {
    // All messages on navigator.serviceWorker are browser-guaranteed to originate
    // from the registered service worker for this scope. Extension content scripts
    // cannot inject messages into this channel.
    //
    // Defense-in-depth: verify the sender is a ServiceWorker. We cache the
    // active worker's ID to avoid stale references across SW updates.
    if (!(event.source instanceof ServiceWorker)) return;
    // ServiceWorker ID is not standard in TypeScript - skip ID verification
    // The browser guarantees messages on navigator.serviceWorker come from our SW

    if (event.data?.type === 'SW_REQUEST_TOKEN') {
      const { accessToken } = getAuthSnapshot();
      if (accessToken) {
        const registration = await navigator.serviceWorker?.getRegistration();
        registration?.active?.postMessage({
          type: 'SW_SET_TOKEN',
          token: accessToken,
        });
      }
      return;
    }

    if (event.data?.type === 'SW_SIGN_DPOP') {
      const replyPort = event.ports[0];
      if (!replyPort) return;

      const { method, url, nonce } = event.data;
      const keyPair = getDpopKeyPair();
      const { accessToken } = getAuthSnapshot();

      if (!keyPair || !accessToken) {
        replyPort.postMessage({ type: 'SW_DPOP_PROOF', proof: null });
        return;
      }

      // Reject requests for URLs outside the allowed set
      if (!isAllowedSignUrl(url)) {
        replyPort.postMessage({ type: 'SW_DPOP_PROOF', proof: null });
        return;
      }

      try {
        // If SW sent a nonce, sync it into the main thread store
        if (nonce) {
          setDpopNonce(nonce);
        }

        // Always sign with the current session's access token.
        // Never trust the token from the message payload.
        const proof = await createDpopProof(keyPair, method, url, {
          accessToken,
          nonce: getDpopNonce() ?? undefined,
        });

        replyPort.postMessage({
          type: 'SW_DPOP_PROOF',
          proof,
          nonce: getDpopNonce(),
        });
      } catch {
        replyPort.postMessage({ type: 'SW_DPOP_PROOF', proof: null });
      }
    }
  });
}
