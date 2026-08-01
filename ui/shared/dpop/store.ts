/**
 * In-memory cache for DPoP key pair and nonce.
 *
 * Hydrated from persistent storage on boot by auth-store.initAuth().
 * The CryptoKeyPair private key is non-exportable -- it survives IndexedDB
 * structured clone but can never be extracted as raw key material.
 */

import type {DpopKeyPair} from './key-pair';

let currentKeyPair: DpopKeyPair | null = null;
let currentNonce: string | null = null;

export function getDpopKeyPair(): DpopKeyPair | null {
  return currentKeyPair;
}

export function setDpopKeyPair(pair: DpopKeyPair): void {
  currentKeyPair = pair;
}

export function clearDpopKeyPair(): void {
  currentKeyPair = null;
}

export function getDpopNonce(): string | null {
  return currentNonce;
}

export function setDpopNonce(nonce: string): void {
  currentNonce = nonce;
}
