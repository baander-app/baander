/**
 * Crypto platform abstraction.
 *
 * Web/Electron: Uses the native Web Crypto API (crypto.subtle).
 * React Native: Uses react-native-quick-crypto (JSI polyfill).
 *
 * Import from this module -- never use global crypto.subtle directly.
 */

export interface CryptoBackend {
  subtle: {
    generateKey(
      algorithm: EcKeyGenParams,
      extractable: boolean,
      keyUsages: KeyUsage[],
    ): Promise<CryptoKeyPair>;

    exportKey(format: 'jwk', key: CryptoKey): Promise<JsonWebKey>;

    importKey(
      format: 'jwk',
      keyData: JsonWebKey,
      algorithm: EcKeyImportParams,
      extractable: boolean,
      keyUsages: KeyUsage[],
    ): Promise<CryptoKey>;

    sign(
      algorithm: EcdsaParams,
      key: CryptoKey,
      data: BufferSource,
    ): Promise<ArrayBuffer>;

    digest(
      algorithm: AlgorithmIdentifier,
      data: BufferSource,
    ): Promise<ArrayBuffer>;
  };

  randomUUID(): string;

  base64urlEncode(buffer: ArrayBuffer | Uint8Array): string;
}
