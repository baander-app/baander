/**
 * DPoP proof JWT creation per RFC 9449.
 *
 * Follows Auth0's DPoP implementation pattern:
 * - ES256 signing algorithm
 * - typ: "dpop+jwt"
 * - jwk header with public key
 * - Required claims: jti, htm, htu, iat
 * - Optional ath claim (SHA-256 hash of access token) for API calls
 * - Optional nonce claim for nonce challenge-response flow
 */

import { getCryptoBackend } from '../crypto/platform-resolver';

/**
 * Create a DPoP proof JWT signed with ES256.
 *
 * @param keyPair - The DPoP key pair
 * @param method - HTTP method (e.g., 'POST', 'GET')
 * @param url - HTTP URI without query/fragment (e.g., 'https://api.example.com/token')
 * @param options.accessToken - Optional access token for ath claim (API calls)
 * @param options.nonce - Optional nonce from DPoP-Nonce response header
 */
export async function createDpopProof(
  keyPair: { privateKey: CryptoKey; jwk: JsonWebKey },
  method: string,
  url: string,
  options?: { accessToken?: string; nonce?: string },
): Promise<string> {
  const crypto = getCryptoBackend();

  const header = {
    typ: 'dpop+jwt',
    alg: 'ES256',
    jwk: keyPair.jwk,
  };

  const now = Math.floor(Date.now() / 1000);

  const payload: Record<string, unknown> = {
    jti: crypto.randomUUID(),
    htm: method.toUpperCase(),
    htu: url,
    iat: now,
  };

  if (options?.accessToken) {
    payload.ath = await hashAccessToken(options.accessToken);
  }

  if (options?.nonce) {
    payload.nonce = options.nonce;
  }

  return signJwt(header, payload, keyPair.privateKey);
}

/**
 * Compute base64url-encoded SHA-256 hash of an access token (ath claim).
 */
async function hashAccessToken(token: string): Promise<string> {
  const crypto = getCryptoBackend();
  const encoded = new TextEncoder().encode(token);
  const hash = await crypto.subtle.digest('SHA-256', encoded);
  return crypto.base64urlEncode(hash);
}

/**
 * Sign a JWT manually (header.payload.signature) using ES256.
 */
async function signJwt(
  header: Record<string, unknown>,
  payload: Record<string, unknown>,
  privateKey: CryptoKey,
): Promise<string> {
  const crypto = getCryptoBackend();
  const headerB64 = crypto.base64urlEncode(new TextEncoder().encode(JSON.stringify(header)));
  const payloadB64 = crypto.base64urlEncode(new TextEncoder().encode(JSON.stringify(payload)));
  const signingInput = `${headerB64}.${payloadB64}`;

  const signature = await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    privateKey,
    new TextEncoder().encode(signingInput),
  );

  return `${signingInput}.${crypto.base64urlEncode(signature)}`;
}
