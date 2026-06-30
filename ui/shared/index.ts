/**
 * @baander/shared -- shared primitives for web + RN apps.
 *
 * The Axios instance stays app-level (web, RN) because it couples to
 * app-specific auth stores. This package exports the building blocks:
 * - DPoP proof generation (RFC 9449)
 * - BlurHash encoding/decoding
 * - Crypto primitives (platform-aware)
 * - Auth storage interface + IndexedDB implementation
 */

// DPoP
export { createDpopProof } from './dpop/proof';
export { generateDpopKeyPair, type DpopKeyPair } from './dpop/key-pair';
export {
  getDpopKeyPair,
  setDpopKeyPair,
  clearDpopKeyPair,
  getDpopNonce,
  setDpopNonce,
} from './dpop/store';

// BlurHash (cross-platform core)
export { encode, encodePixelData, extractComponents } from './blurhash/encode';
export { decode, decodeToArray } from './blurhash/decode';
export type { PixelData } from './blurhash/encode';
export type { DecodedBlurhash } from './blurhash/decode';
export type { Components, EncodeOptions, DecodeOptions } from './blurhash/types';

// Crypto
export { getCryptoBackend, setCryptoBackend } from './crypto/platform-resolver';
export type { CryptoBackend } from './crypto/platform';

// Auth storage
export type { AuthStorageBackend, StoredAuth } from './crypto/auth-storage';
export { IndexedDbAuthStorage } from './crypto/auth-db';
