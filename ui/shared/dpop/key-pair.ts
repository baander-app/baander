/**
 * DPoP key pair management using ES256 (P-256) per RFC 9449.
 *
 * Follows Auth0's DPoP implementation pattern:
 * - ECDSA P-256 key pair (ES256 algorithm)
 * - Public key exported as JWK for inclusion in DPoP proof header
 * - JWK thumbprint (SHA-256 of canonical JWK members) for cnf.jkt binding
 */

import { getCryptoBackend } from '../crypto/platform-resolver';

export interface DpopKeyPair {
  publicKey: CryptoKey
  privateKey: CryptoKey
  jwk: JsonWebKey
  jkt: string
}

/**
 * Generate a new DPoP key pair using ES256 (P-256).
 *
 * Chrome applies the extractable flag to BOTH keys in the pair, so we cannot
 * generate with extractable:false and then export the public key JWK.
 *
 * Strategy: generate extractable, export the public JWK and private JWK,
 * then re-import the private key as non-exportable. The public key from the
 * original generation is used directly (it remains extractable, which is
 * harmless -- it's public material).
 *
 * The non-exportable private key survives IndexedDB structured clone
 * across page reloads.
 */
export async function generateDpopKeyPair(): Promise<DpopKeyPair> {
  const crypto = getCryptoBackend();

  // Step 1: Generate extractable so we can export both JWKs
  const keyPair = await crypto.subtle.generateKey(
    { name: 'ECDSA', namedCurve: 'P-256' },
    true,
    ['sign', 'verify'],
  );

  // Step 2: Export JWKs while extractable
  const publicJwk = await crypto.subtle.exportKey('jwk', keyPair.publicKey) as JsonWebKey;
  const privateJwk = await crypto.subtle.exportKey('jwk', keyPair.privateKey) as JsonWebKey;

  // Step 3: Re-import private key as non-exportable -- it can sign but can never be extracted
  const nonExportablePrivateKey = await crypto.subtle.importKey(
    'jwk',
    privateJwk,
    { name: 'ECDSA', namedCurve: 'P-256' },
    false,
    ['sign'],
  );

  // Zero out the private JWK bytes from the JS object (best-effort memory hygiene)
  privateJwk.d = '';
  privateJwk.dp = '';
  privateJwk.dq = '';
  privateJwk.q = '';
  privateJwk.qi = '';
  privateJwk.p = '';
  privateJwk.k = '';

  const jkt = await computeJwkThumbprint(publicJwk);

  return {
    publicKey: keyPair.publicKey,
    privateKey: nonExportablePrivateKey,
    jwk: publicJwk,
    jkt,
  };
}

/**
 * Compute the JWK SHA-256 thumbprint per RFC 7638.
 *
 * For EC P-256, the canonical members are: crv, kty, x, y
 */
async function computeJwkThumbprint(jwk: JsonWebKey): Promise<string> {
  const crypto = getCryptoBackend();

  const canonical = {
    crv: (jwk as EcJsonWebKey).crv,
    kty: (jwk as EcJsonWebKey).kty,
    x: (jwk as EcJsonWebKey).x,
    y: (jwk as EcJsonWebKey).y,
  };

  const canonicalJson = JSON.stringify(canonical);
  const encoded = new TextEncoder().encode(canonicalJson);
  const hash = await crypto.subtle.digest('SHA-256', encoded);

  return crypto.base64urlEncode(hash);
}

interface EcJsonWebKey extends JsonWebKey {
  crv: string;
  kty: string;
  x: string;
  y: string;
}
