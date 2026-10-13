/**
 * Web-specific adapter over the shared IndexedDbAuthStorage.
 *
 * The web auth-store keeps the flat record shape (cryptoKeyPair, publicJwk, jkt, ...); the shared
 * storage keeps a DpopKeyPair. Both carry the epoch and the tombstone that make every write a
 * compare-and-put; see `../../../../shared/crypto/auth-storage`.
 */

import type {
  PendingLogoutEntry,
  PendingLogoutPatch,
  StoredAuth,
  StoredAuthData,
  StoredAuthTombstone,
  StoredAuthUser,
} from '../../../../shared/crypto/auth-storage';
import { IndexedDbAuthStorage } from '../../../../shared/crypto/auth-db';

export type {
  StoredAuth,
  AuthStorageBackend,
  StoredAuthTombstone,
  PendingLogoutEntry,
  PendingLogoutPatch,
} from '../../../../shared/crypto/auth-storage';
import { PENDING_LOGOUT_LIMIT, isClearedRecord } from '../../../../shared/crypto/auth-storage';

export { PENDING_LOGOUT_LIMIT, isClearedRecord };

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
  if (isClearedRecord(record)) return record;
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

/**
 * Records a logout and leaves the tombstone in one transaction; see `AuthStorageBackend.beginLogout`.
 * Resolves the tombstone's epoch.
 */
export async function beginStoredLogout(entry: PendingLogoutEntry): Promise<number> {
  return storage.beginLogout(entry);
}

/** The pending logouts, oldest first. */
export async function listPendingLogouts(): Promise<PendingLogoutEntry[]> {
  return storage.listPendingLogouts();
}

/** Patches one pending logout; resolves false when it is gone. */
export async function updatePendingLogout(id: string, patch: PendingLogoutPatch): Promise<boolean> {
  return storage.updatePendingLogout(id, patch);
}

/** Removes one pending logout; a missing entry is fine. */
export async function removePendingLogout(id: string): Promise<void> {
  return storage.removePendingLogout(id);
}
