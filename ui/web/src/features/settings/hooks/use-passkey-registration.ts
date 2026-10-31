import { useLiveCoordinator } from '@/shared/live/context';
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership';
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
  const attemptRef = useRef<symbol | null>(null);
  const queryClient = useQueryClient();
  const coordinator = useLiveCoordinator();

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      attemptRef.current = null;
    };
  }, []);

  const register = useCallback(async (name = 'Passkey') => {
    if (!mountedRef.current) return;
    const attempt = Symbol('passkey-registration');
    attemptRef.current = attempt;
    const owner = captureOwnership();
    const ownsAttempt = () => mountedRef.current && attemptRef.current === attempt;
    const current = () => ownsAttempt() && isCurrentOwnership(owner);
    const release = coordinator?.beginMutation(['account']);
    setState({ loading: true, error: null, success: false });

    try {
      const result = await postPasskeyOptions({ method: 'POST' }) as unknown as { data: PasskeyOptionsResponse };
      if (!current()) return;
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

      if (!current()) return;
      if (!credential) {
        if (current()) {
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

      if (current()) {
        setState({ loading: false, error: null, success: true });
        if (!coordinator) queryClient.invalidateQueries({ queryKey: PASSKEYS_QUERY_KEY });
      }
    } catch (err) {
      if (!current()) return;

      if (err instanceof DOMException && err.name === 'NotAllowedError') {
        // User cancelled the WebAuthn dialog — not an error
        setState({ loading: false, error: null, success: false });
        return;
      }

      const message =
        err instanceof Error ? err.message : 'Failed to register passkey';
      setState({ loading: false, error: message, success: false });
    } finally {
      release?.();
      if (ownsAttempt()) {
        attemptRef.current = null;
        setState(current => current.loading ? { ...current, loading: false } : current);
      }
    }
  }, [queryClient, coordinator]);

  return {
    register,
    loading: state.loading,
    error: state.error,
    success: state.success,
  };
}
