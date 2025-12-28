import { useCallback, useEffect, useMemo, useState } from 'react';
import { resolveCredentialStore } from './credentialStore';
import { useAuthStore } from '@/app/modules/auth/store';
import { LOCAL_STORAGE_KEY } from '@/app/common/constants';

type LoginInput = { email: string; password: string };

export function useRemember() {
  const { login } = useAuthStore();
  const store = useMemo(resolveCredentialStore, []);
  const [remember, setRemember] = useState(false);
  const [email, setEmail] = useState<string>('');

  // Prefill email from localStorage
  useEffect(() => {
    const json = localStorage.getItem(LOCAL_STORAGE_KEY.USER_NAME);
    if (!json) {
      return;
    }
    const saved = JSON.parse(json) as string;
    if (saved) {
      setEmail(saved);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!store.supported || !email) return;
      const pwd = await store.get(email);
      if (!cancelled && pwd) {
        await login({ email, password: pwd }, true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [email, login, store]);

  const handleSubmit = useCallback(
    async (payload: LoginInput, rememberMe: boolean) => {
      // Persist last email for prefill (plain string for cross-platform)
      localStorage.setItem(LOCAL_STORAGE_KEY.USER_NAME, JSON.stringify(payload.email));

      await login(payload, rememberMe);

      // Only persist password where supported (Electron)
      if (rememberMe && store.supported) {
        await store.save(payload.email, payload.password);
      } else if (store.supported) {
        // Ensure clearing on uncheck
        await store.clear(payload.email);
      }
    },
    [login, store]
  );

  return {
    remember,
    setRemember,
    email,
    setEmail,
    handleSubmit,
    credentialStoreSupported: store.supported,
  };
}
