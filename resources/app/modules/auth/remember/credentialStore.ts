import { isElectron } from '@/app/utils/platform.ts';

export interface CredentialStore {
  readonly supported: boolean;
  get(email: string): Promise<string | null>;
  save(email: string, password: string): Promise<void>;
  clear(email: string): Promise<void>;
}

class ElectronCredentialStore implements CredentialStore {
  supported = true;

  async get(email: string): Promise<string | null> {
    try {
      const pwd = await (window as any).BaanderElectron?.config?.getUser?.(email);
      return pwd || null;
    } catch {
      return null;
    }
  }

  async save(email: string, password: string): Promise<void> {
    await (window as any).BaanderElectron?.config?.setUser?.(email, password);
  }

  async clear(email: string): Promise<void> {
    await (window as any).BaanderElectron?.config?.deleteUser?.(email);
  }
}

class WebNoopCredentialStore implements CredentialStore {
  supported = false;
  async get(): Promise<string | null> {
    return null;
  }
  async save(): Promise<void> {
    /* no-op on web for security */
  }
  async clear(): Promise<void> {
    /* no-op */
  }
}

export function resolveCredentialStore(): CredentialStore {
  return isElectron() ? new ElectronCredentialStore() : new WebNoopCredentialStore();
}
