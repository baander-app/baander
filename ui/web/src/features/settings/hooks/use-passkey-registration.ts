import { useCallback, useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { postPasskeyOptions, postPasskeyRegister } from '@/shared/api-client/gen/endpoints';
import {
  base64ToArrayBuffer,
  publicKeyCredentialToJSON,
  type PasskeyOptionsResponse,
} from '../utils/webauthn-utils';

interface PasskeyRegistrationState {
  loading: boolean;
  error: string | null;
  success: boolean;
}

const PASSKEYS_QUERY_KEY = ['passkeys'] as const;

export function usePasskeyRegistration() {
  const [state, setState] = useState<PasskeyRegistrationState>({
    loading: false,
    error: null,
    success: false,
  });
  const mountedRef = useRef(true);
  const queryClient = useQueryClient();

  useEffect(() => {
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const register = useCallback(async (name = 'Passkey') => {
    setState({ loading: true, error: null, success: false });

    try {
      const result = await postPasskeyOptions({ method: 'POST' }) as unknown as { data: PasskeyOptionsResponse };
      const { challengeKey, options } = result.data;

      const credential = await navigator.credentials.create({
        publicKey: {
          ...options,
          challenge: base64ToArrayBuffer(options.challenge),
          user: {
            ...options.user,
            id: base64ToArrayBuffer(options.user.id),
          },
          excludeCredentials: options.excludeCredentials?.map((cred) => ({
            ...cred,
            id: base64ToArrayBuffer(cred.id as string),
            transports: cred.transports as AuthenticatorTransport[] | undefined,
          })) as PublicKeyCredentialDescriptor[] | undefined,
        } as PublicKeyCredentialCreationOptions,
      });

      if (!credential) {
        if (mountedRef.current) {
          setState({ loading: false, error: 'Passkey creation was cancelled. Please try again.', success: false });
        }
        return;
      }

      const serialized = publicKeyCredentialToJSON(credential as PublicKeyCredential);
      await postPasskeyRegister({
        challengeKey,
        response: serialized.response as unknown as Record<string, unknown>,
        name,
      });

      if (mountedRef.current) {
        setState({ loading: false, error: null, success: true });
        queryClient.invalidateQueries({ queryKey: PASSKEYS_QUERY_KEY });
      }
    } catch (err) {
      if (!mountedRef.current) return;

      if (err instanceof DOMException && err.name === 'NotAllowedError') {
        // User cancelled the WebAuthn dialog — not an error
        setState({ loading: false, error: null, success: false });
        return;
      }

      const message =
        err instanceof Error ? err.message : 'Failed to register passkey';
      setState({ loading: false, error: message, success: false });
    }
  }, []);

  return {
    register,
    loading: state.loading,
    error: state.error,
    success: state.success,
  };
}
