import { useAuthStore } from '@/features/auth/stores/auth-store'
import { createDpopProof } from '@/shared/crypto/dpop-proof'
import { getDpopKeyPair, getDpopNonce } from '@/shared/crypto/dpop-store'
import { AXIOS_INSTANCE, buildHtu } from './axios-instance'

/**
 * An authenticated `POST` whose URL and headers were built ahead of time, so that it can be sent
 * from a handler that cannot wait: signing a DPoP proof is asynchronous, and a page that is going
 * away has no time left for it. The proof inside is valid for one request, for as long as the
 * server accepts its age.
 */
export interface PreparedKeepaliveRequest {
  readonly url: string
  readonly headers: Readonly<Record<string, string>>
}

/**
 * Builds the headers the API client's request interceptor would add for one JSON `POST` to
 * `path`, from the access token, key pair and nonce as they stand now. Returns null when there
 * are no credentials to sign with, or when they changed while the proof was being signed.
 */
export async function prepareKeepalivePost(path: string, headers: Record<string, string> = {}): Promise<PreparedKeepaliveRequest | null> {
  const { accessToken } = useAuthStore.getState()
  const keyPair = getDpopKeyPair()
  if (!accessToken || !keyPair) return null
  const url = new URL(AXIOS_INSTANCE.getUri({ url: path }), window.location.href).href
  const proof = await createDpopProof(keyPair, 'POST', buildHtu(url), { accessToken, nonce: getDpopNonce() ?? undefined })
  if (useAuthStore.getState().accessToken !== accessToken || getDpopKeyPair() !== keyPair) return null
  return {
    url,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'Content-Type': 'application/json',
      ...headers,
      Authorization: `DPoP ${accessToken}`,
      DPoP: proof,
    },
  }
}

/**
 * Sends a prepared request so that it outlives the page. This is the one API request that does
 * not go through the API client: an unloading page cannot wait for the client's interceptors,
 * and only `fetch` with `keepalive` both carries the headers and survives the unload. Nothing
 * reads the answer, and no nonce challenge or token refresh is retried.
 */
export function sendKeepalivePost(request: PreparedKeepaliveRequest, body: unknown): void {
  fetch(request.url, { method: 'POST', keepalive: true, headers: request.headers, body: JSON.stringify(body) })
    .catch(() => { /* The page is going away: nothing is left to act on a failure. */ })
}

/** Calls `listener` whenever the access token is replaced, which makes every prepared request stale. */
export function onAccessTokenChange(listener: () => void): () => void {
  return useAuthStore.subscribe((state, previous) => {
    if (state.accessToken !== previous.accessToken) listener()
  })
}
