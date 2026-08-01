# @baander/dpop

DPoP (RFC 9449) implementation for Baander apps.

## Platform Support

- ✅ Web / Chrome / Firefox / Safari
- ✅ Electron
- ✅ React Native (iOS, Android, macOS, Windows, tvOS)

## Installation

```bash
yarn add @baander/dpop
```

## Setup

### Web / Electron

No setup required - uses Web Crypto API automatically.

### React Native

Initialize the crypto backend at app startup:

```typescript
import { setCryptoBackend } from '@baander/dpop';
import rnCrypto from 'react-native-quick-crypto';

// In your app entry point (e.g., App.tsx or index.js)
setCryptoBackend({
  subtle: rnCrypto.subtle,
  randomUUID: rnCrypto.randomUUID,
  base64urlEncode: (buffer) => {
    // react-native-quick-crypto doesn't have base64url, use base64 with replacement
    return rnCrypto.base64(buffer)
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');
  },
});
```

## Usage

```typescript
import {
  generateDpopKeyPair,
  createDpopProof,
  getDpopKeyPair,
  setDpopKeyPair,
} from '@baander/dpop';

// Generate a key pair (do this once per user session)
const keyPair = await generateDpopKeyPair();
setDpopKeyPair(keyPair);

// Create a DPoP proof for an API request
const keyPair = getDpopKeyPair();
if (!keyPair) {
  throw new Error('No DPoP key pair - call generateDpopKeyPair() first');
}

const proof = await createDpopProof(
  keyPair,
  'POST',                           // HTTP method
  'https://api.example.com/token',  // URL without query/fragment
  {
    accessToken: '...',  // Optional: include access token hash
    nonce: '...',        // Optional: server-provided nonce
  }
);

// Include in DPoP header
fetch('https://api.example.com/token', {
  headers: {
    'DPoP': proof,
  },
});
```

## Key Pair Persistence

Key pairs should be stored securely and persisted across app sessions:

```typescript
// On app startup
const stored = await secureStore.get('dpopKeyPair');
if (stored) {
  setDpopKeyPair(JSON.parse(stored));
} else {
  const newKeyPair = await generateDpopKeyPair();
  await secureStore.set('dpopKeyPair', JSON.stringify(newKeyPair.jkt));
  setDpopKeyPair(newKeyPair);
}
```

Note: Only store the JWK thumbprint (`jkt`) - the private key cannot be exported.
