import { browserSupportsWebAuthn, startRegistration, startAuthentication } from '@simplewebauthn/browser';

export function useWebauthn() {
  return {
    browserSupportsWebAuthn,
    startAuthentication,
    startRegistration,
  }
}