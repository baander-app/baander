/**
 * Auth storage backend interface.
 *
 * Web/Electron: Uses IndexedDB (preserves CryptoKey objects via structured clone).
 * React Native: Uses AsyncStorage or MMVK (serializes keys as JWK).
 *
 * One origin holds one record. The record carries an epoch that only grows: every write is a
 * compare-and-put against the epoch the writer holds, so a tab with older credentials can never
 * overwrite a newer rotation or resurrect a session another tab ended. A clear leaves a tombstone
 * (the epoch, nothing else) rather than an empty store, so a pending write still fails against it.
 */

import type { DpopKeyPair } from '../dpop/key-pair';

export interface StoredAuthUser {
  uuid: string;
  email: string;
  publicId: string;
  name: string | null;
  roles: string[];
}

/** The credentials a live record holds. */
export interface StoredAuth {
  epoch: number;
  accessToken: string;
  refreshToken: string;
  user: StoredAuthUser;
  nonce: string | null;
  keyPair: DpopKeyPair | null;
}

/** What a clear leaves behind: the epoch, so an older write cannot bring the session back. */
export interface StoredAuthTombstone {
  epoch: number;
  cleared: true;
}

export type StoredAuthRecord = StoredAuth | StoredAuthTombstone;

export type StoredAuthData = Omit<StoredAuth, 'epoch'>;

export type SaveStoredAuthResult =
  | { ok: true; epoch: number }
  | { ok: false; current: StoredAuthRecord | null };

/** True for a tombstone, whichever live record shape it sits beside. */
export function isClearedRecord<T extends { epoch: number }>(record: T | StoredAuthTombstone): record is StoredAuthTombstone {
  return 'cleared' in record && record.cleared === true;
}

export interface AuthStorageBackend {
  /** The stored record, or null when nothing is stored. A record without an epoch is wiped. */
  load(): Promise<StoredAuthRecord | null>;
  /**
   * Writes `data` at `expectedEpoch + 1` (1 when `null`, which expects an empty store) only when the
   * stored epoch is `expectedEpoch`. On a mismatch nothing is written and the current record is
   * returned for the caller to adopt.
   */
  save(data: StoredAuthData, expectedEpoch: number | null): Promise<SaveStoredAuthResult>;
  /** Replaces whatever is stored with a tombstone at the next epoch and resolves that epoch. */
  clear(): Promise<number>;
}
