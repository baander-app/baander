/**
 * Web-specific adapter over the shared IndexedDbAuthStorage.
 *
 * The web auth-store keeps the flat record shape (cryptoKeyPair, publicJwk, jkt, ...); the shared
 * storage keeps a DpopKeyPair. Both carry the epoch and the tombstone that make every write a
 * compare-and-put; see `../../../../shared/crypto/auth-storage`.
 */

import type { StoredAuth, StoredAuthData, StoredAuthTombstone, StoredAuthUser } from '../../../../shared/crypto/auth-storage';
import { IndexedDbAuthStorage } from '../../../../shared/crypto/auth-db';

export type { StoredAuth, AuthStorageBackend, StoredAuthTombstone } from '../../../../shared/crypto/auth-storage';
export { isClearedRecord } from '../../../../shared/crypto/auth-storage';

/** The flat record shape the web auth-store reads and writes. */
export interface LegacyStoredAuth {
  epoch: number;
  cryptoKeyPair: CryptoKeyPair;
  publicJwk: JsonWebKey;
  jkt: string;
  accessToken: string;
  refreshToken: string;
  user: StoredAuthUser;
  nonce: string | null;
}

export type LegacyStoredAuthData = Omit<LegacyStoredAuth, 'epoch'>;

export type LegacyStoredRecord = LegacyStoredAuth | StoredAuthTombstone;

export type SaveStoredAuthResult =
  | { ok: true; epoch: number }
  | { ok: false; current: LegacyStoredRecord | null };

const storage = new IndexedDbAuthStorage();

function legacyToAuth(record: LegacyStoredAuthData): StoredAuthData {
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
  if (!auth.keyPair) {
    throw new Error('Stored auth record has no key pair');
  }
  return {
    epoch: auth.epoch,
    cryptoKeyPair: {
      publicKey: auth.keyPair.publicKey,
      privateKey: auth.keyPair.privateKey,
    },
    publicJwk: auth.keyPair.jwk,
    jkt: auth.keyPair.jkt,
    accessToken: auth.accessToken,
    refreshToken: auth.refreshToken,
    user: auth.user,
    nonce: auth.nonce,
  };
}

function recordToLegacy(record: StoredAuth | StoredAuthTombstone | null): LegacyStoredRecord | null {
  if (record === null) return null;
  if ('cleared' in record) return { epoch: record.epoch, cleared: true };
  return authToLegacy(record);
}

/** The stored record (live or tombstone), or null when nothing is stored. */
export async function loadStoredAuth(): Promise<LegacyStoredRecord | null> {
  return recordToLegacy(await storage.load());
}

/** Compare-and-put at `expectedEpoch`; see `AuthStorageBackend.save`. */
export async function saveStoredAuth(data: LegacyStoredAuthData, expectedEpoch: number | null): Promise<SaveStoredAuthResult> {
  const result = await storage.save(legacyToAuth(data), expectedEpoch);
  if (result.ok) return result;
  return { ok: false, current: recordToLegacy(result.current) };
}

/** Leaves a tombstone at the next epoch and resolves that epoch. */
export async function clearStoredAuth(): Promise<number> {
  return storage.clear();
}
