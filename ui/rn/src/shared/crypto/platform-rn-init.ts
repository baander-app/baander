/**
 * RN crypto platform initialization.
 *
 * Sets up react-native-quick-crypto as the crypto backend for @baander/shared.
 * Must be called before any DPoP operations (before login/initAuth).
 *
 * Usage in App.tsx:
 *   import { initCrypto } from '@/shared/crypto/platform-rn-init';
 *   initCrypto();
 */

import { setCryptoBackend } from '@baander/shared/crypto/platform-resolver';
import rnCrypto from '@baander/shared/crypto/platform-rn';

let initialized = false;

export function initCrypto(): void {
  if (initialized) {
    return;
  }

  initialized = true;
  setCryptoBackend(rnCrypto);
}
