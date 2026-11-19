import { useLiveCoordinator } from '@/shared/live/context';
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { postPasskeyOptions, postPasskeyRegister } from '@/shared/api-client/gen/endpoints';
import type { StepUpCredentials } from '../components/StepUpDialog';
import {
  base64ToArrayBuffer,
  publicKeyCredentialToJSON,
  type PasskeyOptionsResponse,
} from '../utils/webauthn-utils';

interface PasskeyRegistrationState {
  loading: boolean;
  success: boolean;
}

const PASSKEYS_QUERY_KEY = ['passkeys'] as const;

/**
 * Registers a passkey for the signed-in user. The registration options need step-up, so
 * `register` takes the current password and code; it resolves true once the passkey is
 * stored and false when the ceremony was cancelled or superseded, and rejects with the
 * failure so the step-up prompt can show it and start over.
 */
export function usePasskeyRegistration() {
  const [state, setState] = useState<PasskeyRegistrationState>({
    loading: false,
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

  const register = useCallback(async (name: string, stepUp: StepUpCredentials): Promise<boolean> => {
    if (!mountedRef.current) return false;
    const attempt = Symbol('passkey-registration');
    attemptRef.current = attempt;
    const owner = captureOwnership();
    const ownsAttempt = () => mountedRef.current && attemptRef.current === attempt;
    const current = () => ownsAttempt() && isCurrentOwnership(owner);
    const release = coordinator?.beginMutation(['account']);
    setState({ loading: true, success: false });

    try {
      const result = await postPasskeyOptions(stepUp) as unknown as { data: PasskeyOptionsResponse };
      if (!current()) return false;
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

      // No credential means the browser's prompt ended without one, like a cancel.
      if (!current() || !credential) return false;

      // The server reads the whole credential: its ID, type and attestation response.
      const serialized = publicKeyCredentialToJSON(credential as PublicKeyCredential);
      await postPasskeyRegister({
        challengeKey,
        response: serialized as unknown as Record<string, unknown>,
        name,
      });

      if (!current()) return false;
      setState({ loading: false, success: true });
      if (!coordinator) queryClient.invalidateQueries({ queryKey: PASSKEYS_QUERY_KEY });

      return true;
    } catch (err) {
      // The user closed the browser's passkey dialog: not an error.
      if (!current() || (err instanceof DOMException && err.name === 'NotAllowedError')) return false;

      throw err;
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
    success: state.success,
  };
}
