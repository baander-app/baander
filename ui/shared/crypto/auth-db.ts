/**
 * IndexedDB persistence for auth state (Web/Electron).
 *
 * Stores the CryptoKeyPair (non-exportable private key) via structured clone.
 * IndexedDB preserves CryptoKey objects including their non-exportable status,
 * so the private key can sign DPoP proofs but can never be extracted as raw material.
 *
 * A save is get, compare, put inside one readwrite transaction with nothing awaited in between,
 * so two tabs writing at the same epoch cannot both succeed: IndexedDB serializes readwrite
 * transactions on the store. The transaction aborts on a mismatch and is never retried here.
 *
 * The pending logouts live in a second record of the same store, a list under one id, so a logout
 * writes its entry and the credential tombstone in one transaction. Every list change is one
 * readwrite transaction that reads the list, changes it and puts it back, which IndexedDB
 * serializes against the other tabs' changes.
 */

import type {
  AuthStorageBackend,
  PendingLogoutEntry,
  PendingLogoutPatch,
  SaveStoredAuthResult,
  StoredAuth,
  StoredAuthData,
  StoredAuthRecord,
} from './auth-storage';

const DB_NAME = 'baander-auth';
const DB_VERSION = 1;
const STORE_NAME = 'auth';
const RECORD_ID = 'current';
const PENDING_LOGOUTS_ID = 'pending-logouts';

interface LiveRecord {
  id: string;
  epoch: number;
  cleared: false;
  cryptoKeyPair: CryptoKeyPair;
  publicJwk: JsonWebKey;
  jkt: string;
  accessToken: string;
  refreshToken: string;
  user: StoredAuth['user'];
  nonce: string | null;
}

interface TombstoneRecord {
  id: string;
  epoch: number;
  cleared: true;
}

type IndexedDbRecord = LiveRecord | TombstoneRecord;

/** One pending logout as stored: the key pair flat beside its JWK, as the live record keeps it. */
interface StoredPendingLogout {
  id: string;
  createdAt: number;
  cryptoKeyPair: CryptoKeyPair;
  publicJwk: JsonWebKey;
  jkt: string;
  accessToken: string;
  refreshToken: string;
  accessTokenExpiresAt: number | null;
  origin: string;
  attempts: number;
}

interface PendingLogoutsRecord {
  id: string;
  entries: StoredPendingLogout[];
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'id' });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

/** A record written before the epoch existed is not trusted; the caller wipes it. */
function hasEpoch(record: unknown): record is IndexedDbRecord {
  return typeof record === 'object' && record !== null && typeof (record as { epoch?: unknown }).epoch === 'number';
}

function recordToAuth(record: IndexedDbRecord): StoredAuthRecord {
  if (record.cleared) {
    return { epoch: record.epoch, cleared: true };
  }
  return {
    epoch: record.epoch,
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

function liveRecord(data: StoredAuthData, epoch: number): LiveRecord {
  if (!data.keyPair) {
    throw new Error('Cannot save auth without key pair to IndexedDB');
  }
  return {
    id: RECORD_ID,
    epoch,
    cleared: false,
    cryptoKeyPair: {
      publicKey: data.keyPair.publicKey,
      privateKey: data.keyPair.privateKey,
    },
    publicJwk: data.keyPair.jwk,
    jkt: data.keyPair.jkt,
    accessToken: data.accessToken,
    refreshToken: data.refreshToken,
    user: data.user,
    nonce: data.nonce,
  };
}

function toStoredPendingLogout(entry: PendingLogoutEntry): StoredPendingLogout {
  return {
    id: entry.id,
    createdAt: entry.createdAt,
    cryptoKeyPair: {
      publicKey: entry.keyPair.publicKey,
      privateKey: entry.keyPair.privateKey,
    },
    publicJwk: entry.keyPair.jwk,
    jkt: entry.keyPair.jkt,
    accessToken: entry.accessToken,
    refreshToken: entry.refreshToken,
    accessTokenExpiresAt: entry.accessTokenExpiresAt,
    origin: entry.origin,
    attempts: entry.attempts,
  };
}

/** The entry as the delivery reads it; a key that did not survive the store reads back as missing. */
function toPendingLogoutEntry(stored: StoredPendingLogout): PendingLogoutEntry {
  return {
    id: stored.id,
    createdAt: stored.createdAt,
    keyPair: {
      publicKey: stored.cryptoKeyPair?.publicKey,
      privateKey: stored.cryptoKeyPair?.privateKey,
      jwk: stored.publicJwk,
      jkt: stored.jkt,
    },
    accessToken: stored.accessToken,
    refreshToken: stored.refreshToken,
    accessTokenExpiresAt: stored.accessTokenExpiresAt ?? null,
    origin: stored.origin,
    attempts: stored.attempts ?? 0,
  };
}

/** The stored list, or an empty one when the record is missing or not a list. */
function pendingEntries(raw: unknown): StoredPendingLogout[] {
  const entries = (raw as Partial<PendingLogoutsRecord> | null | undefined)?.entries;
  if (!Array.isArray(entries)) return [];
  return entries.filter((entry): entry is StoredPendingLogout => typeof entry === 'object' && entry !== null && typeof entry.id === 'string');
}

export class IndexedDbAuthStorage implements AuthStorageBackend {
  async load(): Promise<StoredAuthRecord | null> {
    const db = await openDb();
    const raw = await new Promise<unknown>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly');
      const request = tx.objectStore(STORE_NAME).get(RECORD_ID);

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => { db.close(); reject(request.error); };
      tx.oncomplete = () => db.close();
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
    if (raw === undefined || raw === null) return null;
    if (hasEpoch(raw)) return recordToAuth(raw);
    await this.wipe();
    return null;
  }

  async save(data: StoredAuthData, expectedEpoch: number | null): Promise<SaveStoredAuthResult> {
    const record = liveRecord(data, (expectedEpoch ?? 0) + 1);
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      let mismatch: StoredAuthRecord | null | undefined;

      const read = store.get(RECORD_ID);
      read.onsuccess = () => {
        const current = read.result as unknown;
        const currentEpoch = hasEpoch(current) ? current.epoch : null;
        if (currentEpoch !== expectedEpoch) {
          mismatch = hasEpoch(current) ? recordToAuth(current) : null;
          tx.abort();
          return;
        }
        store.put(record);
      };

      tx.oncomplete = () => { db.close(); resolve({ ok: true, epoch: record.epoch }); };
      tx.onabort = () => {
        db.close();
        if (mismatch !== undefined) resolve({ ok: false, current: mismatch });
        else reject(tx.error ?? new Error('Auth record write aborted'));
      };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }

  async clear(): Promise<number> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      let epoch = 0;

      const read = store.get(RECORD_ID);
      read.onsuccess = () => {
        const current = read.result as unknown;
        epoch = (hasEpoch(current) ? current.epoch : 0) + 1;
        const tombstone: TombstoneRecord = { id: RECORD_ID, epoch, cleared: true };
        store.put(tombstone);
      };

      tx.oncomplete = () => { db.close(); resolve(epoch); };
      tx.onabort = () => { db.close(); reject(tx.error ?? new Error('Auth record clear aborted')); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }

  async beginLogout(entry: PendingLogoutEntry): Promise<number> {
    const stored = toStoredPendingLogout(entry);
    let epoch = 0;
    await this.readwrite((store) => {
      const readCurrent = store.get(RECORD_ID);
      readCurrent.onsuccess = () => {
        epoch = (hasEpoch(readCurrent.result) ? readCurrent.result.epoch : 0) + 1;
      };
      const readList = store.get(PENDING_LOGOUTS_ID);
      readList.onsuccess = () => {
        const entries = pendingEntries(readList.result).filter((existing) => existing.id !== stored.id);
        entries.push(stored);
        store.put({ id: PENDING_LOGOUTS_ID, entries } satisfies PendingLogoutsRecord);
        const tombstone: TombstoneRecord = { id: RECORD_ID, epoch, cleared: true };
        store.put(tombstone);
      };
    });
    return epoch;
  }

  async listPendingLogouts(): Promise<PendingLogoutEntry[]> {
    const db = await openDb();
    const raw = await new Promise<unknown>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly');
      const request = tx.objectStore(STORE_NAME).get(PENDING_LOGOUTS_ID);

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => { db.close(); reject(request.error); };
      tx.oncomplete = () => db.close();
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
    return pendingEntries(raw).map(toPendingLogoutEntry);
  }

  async updatePendingLogout(id: string, patch: PendingLogoutPatch): Promise<boolean> {
    let found = false;
    await this.readwrite((store) => {
      const read = store.get(PENDING_LOGOUTS_ID);
      read.onsuccess = () => {
        const entries = pendingEntries(read.result);
        const index = entries.findIndex((entry) => entry.id === id);
        if (index === -1) return;
        found = true;
        entries[index] = { ...entries[index], ...patch };
        store.put({ id: PENDING_LOGOUTS_ID, entries } satisfies PendingLogoutsRecord);
      };
    });
    return found;
  }

  async removePendingLogout(id: string): Promise<void> {
    await this.readwrite((store) => {
      const read = store.get(PENDING_LOGOUTS_ID);
      read.onsuccess = () => {
        const entries = pendingEntries(read.result);
        if (!entries.some((entry) => entry.id === id)) return;
        store.put({ id: PENDING_LOGOUTS_ID, entries: entries.filter((entry) => entry.id !== id) } satisfies PendingLogoutsRecord);
      };
    });
  }

  /** Runs `work` against the store in one readwrite transaction; resolves once it committed. */
  private async readwrite(work: (store: IDBObjectStore) => void): Promise<void> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      work(tx.objectStore(STORE_NAME));

      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onabort = () => { db.close(); reject(tx.error ?? new Error('Auth store write aborted')); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }

  private async wipe(): Promise<void> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).delete(RECORD_ID);

      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }
}
