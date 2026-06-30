/**
 * Crypto platform resolver.
 *
 * Web/Electron: auto-detects and returns the Web Crypto backend.
 * React Native: must be explicitly initialized via setCryptoBackend(rnCrypto)
 *   before any DPoP operations.
 *
 * This indirection exists because:
 * 1. The shared package cannot import react-native-quick-crypto directly
 *    (it's a native module, not available in web builds).
 * 2. Platform detection in a shared package must be explicit, not implicit
 *    via `typeof window` checks that break in SSR or test environments.
 */

import type { CryptoBackend } from './platform';
import webCrypto from './platform-web';

let backend: CryptoBackend | null = null;

/**
 * Get the current crypto backend. Defaults to Web Crypto if not explicitly set.
 */
export function getCryptoBackend(): CryptoBackend {
  if (!backend) {
    backend = webCrypto;
  }
  return backend;
}

/**
 * Set the crypto backend. Called by the RN app at startup:
 *
 *   import { setCryptoBackend } from '@baander/shared/crypto';
 *   import rnCrypto from '@baander/shared/crypto/platform-rn';
 *   setCryptoBackend(rnCrypto);
 */
export function setCryptoBackend(b: CryptoBackend): void {
  backend = b;
}
