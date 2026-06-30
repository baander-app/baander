/**
 * Auth storage backend interface.
 *
 * Web/Electron: Uses IndexedDB (preserves CryptoKey objects via structured clone).
 * React Native: Uses AsyncStorage or MMVK (serializes keys as JWK).
 */

import type { DpopKeyPair } from '../dpop/key-pair';

export interface StoredAuth {
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
  keyPair: DpopKeyPair | null;
}

export interface AuthStorageBackend {
  load(): Promise<StoredAuth | null>;
  save(data: StoredAuth): Promise<void>;
  clear(): Promise<void>;
}
