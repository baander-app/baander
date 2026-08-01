/**
 * Web Crypto backend -- uses native crypto.subtle, btoa, crypto.randomUUID().
 * Works in browsers and Electron renderer.
 */

import type { CryptoBackend } from './platform';

const webCrypto: CryptoBackend = {
  subtle: {
    async generateKey(algorithm, extractable, keyUsages) {
      return crypto.subtle.generateKey(algorithm, extractable, keyUsages);
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

  randomUUID() {
    return crypto.randomUUID();
  },

  base64urlEncode(buffer: ArrayBuffer | Uint8Array): string {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  },
};

export default webCrypto;
