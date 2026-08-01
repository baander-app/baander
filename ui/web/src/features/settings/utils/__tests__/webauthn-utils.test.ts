import { describe, it, expect } from 'vitest';
import {
  base64ToArrayBuffer,
  arrayBufferToBase64,
  publicKeyCredentialToJSON,
} from '../webauthn-utils';

function toBuffer(bytes: number[]): ArrayBuffer {
  const buffer = new ArrayBuffer(bytes.length);
  const view = new Uint8Array(buffer);
  bytes.forEach((b, i) => { view[i] = b; });
  return buffer;
}

describe('base64ToArrayBuffer', () => {
  it('converts a base64 string to ArrayBuffer', () => {
    const result = base64ToArrayBuffer('SGVsbG8=');
    expect(new Uint8Array(result)).toEqual(new Uint8Array([72, 101, 108, 108, 111]));
  });

  it('handles empty string', () => {
    const result = base64ToArrayBuffer('');
    expect(result.byteLength).toBe(0);
  });

  it('handles single byte', () => {
    const result = base64ToArrayBuffer('AA==');
    expect(new Uint8Array(result)).toEqual(new Uint8Array([0]));
  });
});

describe('arrayBufferToBase64', () => {
  it('converts ArrayBuffer to base64 string', () => {
    expect(arrayBufferToBase64(toBuffer([72, 101, 108, 108, 111]))).toBe('SGVsbG8=');
  });

  it('handles empty ArrayBuffer', () => {
    expect(arrayBufferToBase64(new ArrayBuffer(0))).toBe('');
  });

  it('handles single byte', () => {
    expect(arrayBufferToBase64(toBuffer([0]))).toBe('AA==');
  });
});

describe('round-trip encoding', () => {
  it('preserves data through base64ToArrayBuffer -> arrayBufferToBase64', () => {
    const original = 'SGVsbG8gV29ybGQ=';
    expect(arrayBufferToBase64(base64ToArrayBuffer(original))).toBe(original);
  });

  it('preserves all byte values 0-255', () => {
    const bytes = Array.from({ length: 256 }, (_, i) => i);
    const buffer = toBuffer(bytes);
    const encoded = arrayBufferToBase64(buffer);
    const decoded = base64ToArrayBuffer(encoded);
    expect(new Uint8Array(decoded)).toEqual(new Uint8Array(buffer));
  });
});

describe('publicKeyCredentialToJSON', () => {
  it('serializes a PublicKeyCredential to a JSON-safe object', () => {
    const rawId = toBuffer([1, 2, 3, 4]);
    const attestationObject = toBuffer([5, 6, 7, 8]);
    const clientDataJSON = toBuffer([9, 10, 11, 12]);

    const credential = {
      id: 'test-cred-id',
      rawId,
      type: 'public-key' as const,
      response: { attestationObject, clientDataJSON } as AuthenticatorAttestationResponse,
    } as unknown as PublicKeyCredential;

    const result = publicKeyCredentialToJSON(credential);

    expect(result.id).toBe('test-cred-id');
    expect(result.type).toBe('public-key');
    expect(result.rawId).toBe(arrayBufferToBase64(rawId));
    expect(result.response.attestationObject).toBe(arrayBufferToBase64(attestationObject));
    expect(result.response.clientDataJSON).toBe(arrayBufferToBase64(clientDataJSON));
  });

  it('produces only string values (no ArrayBuffers)', () => {
    const credential = {
      id: 'id',
      rawId: toBuffer([1]),
      type: 'public-key' as const,
      response: { attestationObject: toBuffer([2]), clientDataJSON: toBuffer([3]) } as AuthenticatorAttestationResponse,
    } as unknown as PublicKeyCredential;

    const result = publicKeyCredentialToJSON(credential);
    const json = JSON.stringify(result);
    expect(() => JSON.parse(json)).not.toThrow();
  });
});
