export interface PasskeyOptionsResponse {
  challengeKey: string;
  options: PublicKeyCredentialCreationOptionsJSON;
}

interface PublicKeyCredentialDescriptorJSON {
  id: string;
  type: string;
  transports?: string[];
}

interface PublicKeyCredentialCreationOptionsJSON {
  challenge: string;
  rp: { name: string; id?: string };
  user: {
    id: string;
    name: string;
    displayName: string;
  };
  pubKeyCredParams: Array<{ type: string; alg: number }>;
  timeout?: number;
  excludeCredentials?: PublicKeyCredentialDescriptorJSON[];
  authenticatorSelection?: {
    authenticatorAttachment?: string;
    requireResidentKey?: boolean;
    residentKey?: string;
    userVerification?: string;
  };
  attestation?: string;
}

export interface SerializedCredential {
  id: string;
  rawId: string;
  type: string;
  response: {
    attestationObject: string;
    clientDataJSON: string;
  };
}

export function base64ToArrayBuffer(base64: string): ArrayBuffer {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer as ArrayBuffer;
}

export function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

export function publicKeyCredentialToJSON(cred: PublicKeyCredential): SerializedCredential {
  const response = cred.response as AuthenticatorAttestationResponse;
  return {
    id: cred.id,
    rawId: arrayBufferToBase64(cred.rawId),
    type: cred.type,
    response: {
      attestationObject: arrayBufferToBase64(response.attestationObject),
      clientDataJSON: arrayBufferToBase64(response.clientDataJSON),
    },
  };
}
