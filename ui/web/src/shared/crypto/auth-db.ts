/**
 * Web-specific re-export of auth-db functions.
 *
 * The web auth-store uses the old standalone function API:
 *   loadStoredAuth() -> { cryptoKeyPair, publicJwk, jkt, accessToken, ... } | null
 *   saveStoredAuth(data) -> void
 *   clearStoredAuth() -> void
 *
 * These wrap the shared IndexedDbAuthStorage which uses the new StoredAuth shape.
 */

import type { StoredAuth } from '../../../../shared/crypto/auth-storage';
import { IndexedDbAuthStorage } from '../../../../shared/crypto/auth-db';

export type { StoredAuth, AuthStorageBackend } from '../../../../shared/crypto/auth-storage';

/**
 * Legacy record shape used by the web auth-store.
 * This matches the old IndexedDB record format.
 */
export interface LegacyStoredAuth {
  id?: string;
  cryptoKeyPair: CryptoKeyPair;
  publicJwk: JsonWebKey;
  jkt: string;
  accessToken: string;
  refreshToken: string;
  user: {
    uuid: string;
    email: string;
    publicId: string;
    name: string | null;
    roles: string[];
  };
  nonce: string | null;
}

const storage = new IndexedDbAuthStorage();

function legacyToAuth(record: LegacyStoredAuth): StoredAuth {
  return {
    accessToken: record.accessToken,
    refreshToken: record.refreshToken,
    user: record.user,
    nonce: record.nonce,
    keyPair: {
      publicKey: record.cryptoKeyPair.publicKey,
      privateKey: record.cryptoKeyPair.privateKey,
      jwk: record.publicJwk,
      jkt: record.jkt,
    },
  };
}

function authToLegacy(auth: StoredAuth): LegacyStoredAuth {
  return {
    cryptoKeyPair: {
      publicKey: auth.keyPair!.publicKey,
      privateKey: auth.keyPair!.privateKey,
    },
    publicJwk: auth.keyPair!.jwk,
    jkt: auth.keyPair!.jkt,
    accessToken: auth.accessToken,
    refreshToken: auth.refreshToken,
    user: auth.user,
    nonce: auth.nonce,
  };
}

/**
 * Load stored auth from IndexedDB (returns legacy format for auth-store compatibility).
 */
export async function loadStoredAuth(): Promise<LegacyStoredAuth | null> {
  const auth = await storage.load();
  if (!auth) return null;
  return authToLegacy(auth);
}

/**
 * Save auth to IndexedDB (accepts legacy format from auth-store).
 */
export async function saveStoredAuth(data: Omit<LegacyStoredAuth, 'id'>): Promise<void> {
  const auth: StoredAuth = legacyToAuth(data as LegacyStoredAuth);
  return storage.save(auth);
}

/**
 * Clear stored auth from IndexedDB.
 */
export async function clearStoredAuth(): Promise<void> {
  return storage.clear();
}
