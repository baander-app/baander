/**
 * Secure key storage for RN using react-native-keychain.
 *
 * Stores the DPoP key pair JWK (private key material) in the device keychain.
 * This replaces IndexedDB (web) with platform secure storage (Keychain iOS / Keystore Android).
 *
 * The public key JWK and JWK thumbprint are stored alongside for reconstruction.
 * Access tokens and refresh tokens are stored in AsyncStorage (via auth-store Zustand persist).
 */

import * as Keychain from 'react-native-keychain';
import type { DpopKeyPair } from '@baander/shared/dpop/key-pair';

const DPOP_KEY_SERVICE = 'app.baander.client.dpop-key';
const DPOP_KEY_USERNAME = 'dpop-keypair';

interface SerializedDpopKey {
  publicJwk: JsonWebKey;
  privateJwk: JsonWebKey;
  jkt: string;
}

/**
 * Serialize a DpopKeyPair for keychain storage.
 * CryptoKey objects cannot be serialized -- store JWKs and reimport on load.
 */
export async function saveDpopKeyPair(keyPair: DpopKeyPair): Promise<void> {
  const { getCryptoBackend } = await import('@baander/shared/crypto/platform-resolver');
  const crypto = getCryptoBackend();

  // Export the private key as JWK for storage
  const privateJwk = await crypto.subtle.exportKey('jwk', keyPair.privateKey);

  const serialized: SerializedDpopKey = {
    publicJwk: keyPair.jwk,
    privateJwk,
    jkt: keyPair.jkt,
  };

  await Keychain.setGenericPassword(
    DPOP_KEY_USERNAME,
    JSON.stringify(serialized),
    { service: DPOP_KEY_SERVICE },
  );
}

/**
 * Load and reconstruct a DpopKeyPair from keychain storage.
 * Reimports the private key from JWK (non-exportable for security).
 */
export async function loadDpopKeyPair(): Promise<DpopKeyPair | null> {
  const result = await Keychain.getGenericPassword({ service: DPOP_KEY_SERVICE });
  if (!result) return null;

  try {
    const serialized: SerializedDpopKey = JSON.parse(result.password);

    const { getCryptoBackend } = await import('@baander/shared/crypto/platform-resolver');
    const crypto = getCryptoBackend();

    // Reimport private key as non-exportable
    const privateKey = await crypto.subtle.importKey(
      'jwk',
      serialized.privateJwk,
      { name: 'ECDSA', namedCurve: 'P-256' },
      false,
      ['sign'],
    );

    // Reimport public key (needed for the CryptoKeyPair)
    const publicKey = await crypto.subtle.importKey(
      'jwk',
      serialized.publicJwk,
      { name: 'ECDSA', namedCurve: 'P-256' },
      true,
      ['verify'],
    );

    return {
      publicKey,
      privateKey,
      jwk: serialized.publicJwk,
      jkt: serialized.jkt,
    };
  } catch {
    return null;
  }
}

/**
 * Clear stored DPoP key pair from keychain.
 */
export async function clearDpopKeyPair(): Promise<void> {
  await Keychain.resetGenericPassword({ service: DPOP_KEY_SERVICE });
}
