/**
 * IndexedDB persistence for auth state (Web/Electron).
 *
 * Stores the CryptoKeyPair (non-exportable private key) via structured clone.
 * IndexedDB preserves CryptoKey objects including their non-exportable status,
 * so the private key can sign DPoP proofs but can never be extracted as raw material.
 */

import type { AuthStorageBackend, StoredAuth } from './auth-storage';

const DB_NAME = 'baander-auth';
const DB_VERSION = 1;
const STORE_NAME = 'auth';
const RECORD_ID = 'current';

interface IndexedDbRecord {
  id: string;
  cryptoKeyPair: CryptoKeyPair;
  publicJwk: JsonWebKey;
  jkt: string;
  accessToken: string;
  refreshToken: string;
  user: StoredAuth['user'];
  nonce: string | null;
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

function recordToAuth(record: IndexedDbRecord): StoredAuth {
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

export class IndexedDbAuthStorage implements AuthStorageBackend {
  async load(): Promise<StoredAuth | null> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly');
      const store = tx.objectStore(STORE_NAME);
      const request = store.get(RECORD_ID);

      request.onsuccess = () => {
        const record = request.result as IndexedDbRecord | undefined;
        resolve(record ? recordToAuth(record) : null);
      };
      request.onerror = () => { db.close(); reject(request.error); };
      tx.oncomplete = () => db.close();
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }

  async save(data: StoredAuth): Promise<void> {
    if (!data.keyPair) {
      throw new Error('Cannot save auth without key pair to IndexedDB');
    }

    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      const record: IndexedDbRecord = {
        id: RECORD_ID,
        cryptoKeyPair: {
          publicKey: data.keyPair!.publicKey,
          privateKey: data.keyPair!.privateKey,
        },
        publicJwk: data.keyPair!.jwk,
        jkt: data.keyPair!.jkt,
        accessToken: data.accessToken,
        refreshToken: data.refreshToken,
        user: data.user,
        nonce: data.nonce,
      };
      store.put(record);

      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }

  async clear(): Promise<void> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      store.delete(RECORD_ID);

      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  }
}
