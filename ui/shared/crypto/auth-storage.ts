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
 *
 * Beside the record, the store keeps the origin's pending logouts: a logout ends the session in the
 * browser at once, and its server request is kept in a list until the server confirms or refuses it.
 */

import type { DpopKeyPair } from '../dpop/key-pair';

export interface StoredAuthUser {
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

/**
 * A logout the server has not confirmed yet. The entry holds everything a later delivery needs:
 * the ended session's DPoP key pair to sign the proof, its tokens, and the access token's expiry so
 * the delivery knows to refresh first. An entry is never adopted as a session.
 */
export interface PendingLogoutEntry {
  id: string;
  /** When the logout was recorded, in milliseconds since the epoch. */
  createdAt: number;
  keyPair: DpopKeyPair;
  accessToken: string;
  refreshToken: string;
  /** The access token's `exp` claim in milliseconds since the epoch, or null when the token carries none. */
  accessTokenExpiresAt: number | null;
  /** The API origin the logout belongs to; an entry of another origin is never sent. */
  origin: string;
  /** Delivery attempts that left the entry in place. */
  attempts: number;
}

/** What a delivery attempt may change: the rotated tokens, their expiry and the attempt count. */
export type PendingLogoutPatch = Partial<Pick<PendingLogoutEntry, 'accessToken' | 'refreshToken' | 'accessTokenExpiresAt' | 'attempts'>>;

/**
 * How many pending logouts the list may hold. The delivery sends the oldest entry beyond this limit
 * once more and then drops it, whatever the server answered.
 */
export const PENDING_LOGOUT_LIMIT = 20;

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
  /**
   * Appends `entry` to the pending logouts and writes the tombstone at the next epoch in one readwrite
   * transaction, so a recorded logout always has its credentials cleared and a cleared session always
   * has its logout recorded. Resolves the tombstone's epoch.
   */
  beginLogout(entry: PendingLogoutEntry): Promise<number>;
  /** The pending logouts, oldest first. */
  listPendingLogouts(): Promise<PendingLogoutEntry[]>;
  /**
   * Applies `patch` to the entry with `id` in one readwrite transaction that re-reads the list.
   * Resolves false, writing nothing, when the entry is gone.
   */
  updatePendingLogout(id: string, patch: PendingLogoutPatch): Promise<boolean>;
  /** Removes the entry with `id` in one readwrite transaction that re-reads the list; a missing entry is fine. */
  removePendingLogout(id: string): Promise<void>;
}
