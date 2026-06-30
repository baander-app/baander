/**
 * React Native crypto backend -- uses react-native-quick-crypto (JSI polyfill).
 *
 * react-native-quick-crypto provides crypto.subtle, but does NOT provide:
 * - crypto.randomUUID() -- polyfill via crypto.getRandomValues
 * - btoa() -- polyfill manually
 */

import type { CryptoBackend } from './platform';
// react-native-quick-crypto is imported at the app level and patches global crypto.
// This file assumes `crypto.subtle` is available on the global.

const rnCrypto: CryptoBackend = {
  subtle: {
    async generateKey(algorithm, extractable, keyUsages) {
      return crypto.subtle.generateKey(algorithm, extractable, keyUsages) as Promise<CryptoKeyPair>;
    },
    async exportKey(format, key) {
      return crypto.subtle.exportKey(format, key);
    },
    async importKey(format, keyData, algorithm, extractable, keyUsages) {
      return crypto.subtle.importKey(format, keyData, algorithm, extractable, keyUsages);
    },
    async sign(algorithm, key, data) {
      return crypto.subtle.sign(algorithm, key, data);
    },
    async digest(algorithm, data) {
      return crypto.subtle.digest(algorithm, data);
    },
  },

  randomUUID(): string {
    // react-native-quick-crypto may not provide randomUUID -- polyfill
    if (typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    // Set version (4) and variant bits per RFC 4122
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  },

  base64urlEncode(buffer: ArrayBuffer | Uint8Array): string {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    // btoa may not exist in RN -- manual base64 encoding
    if (typeof btoa === 'function') {
      return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    // Fallback: manual base64 encoding without Buffer or btoa
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    let result = '';
    for (let i = 0; i < binary.length; i += 3) {
      const c1 = binary.charCodeAt(i);
      const c2 = binary.charCodeAt(i + 1);
      const c3 = binary.charCodeAt(i + 2);
      result += chars[c1 >> 2];
      result += chars[((c1 & 3) << 4) | (c2 >> 4)];
      result += i + 1 < binary.length ? chars[((c2 & 15) << 2) | (c3 >> 6)] : '=';
      result += i + 2 < binary.length ? chars[c3 & 63] : '=';
    }
    return result.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  },
};

export default rnCrypto;
