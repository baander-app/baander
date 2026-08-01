/**
 * @baander/dpop -- DPoP (RFC 9449) implementation for Baander apps.
 *
 * Provides:
 * - DPoP key pair generation (ES256 P-256)
 * - DPoP proof JWT creation
 * - In-memory key pair and nonce cache
 *
 * Depends on @baander/shared for crypto backend primitives.
 */

export { createDpopProof } from './dpop-proof';
export { generateDpopKeyPair, type DpopKeyPair } from './dpop-key-pair';
export {
  getDpopKeyPair,
  setDpopKeyPair,
  clearDpopKeyPair,
  getDpopNonce,
  setDpopNonce,
} from './dpop-store';
