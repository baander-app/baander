import {
  AuthLogin200,
} from '@/app/libs/api-client/gen/models';

const LOCAL_STORAGE_KEY = 'baander_token';

export const Token = {
  get(): AuthLogin200 | undefined {
    try {
      const token = localStorage.getItem(LOCAL_STORAGE_KEY);
      return token ? JSON.parse(token) : undefined;
    } catch {
      // Clear corrupted data
      localStorage.removeItem(LOCAL_STORAGE_KEY);
      return undefined;
    }
  },

  set(token: AuthLogin200): void {
    localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(token));
  },

  clear(): void {
    localStorage.removeItem(LOCAL_STORAGE_KEY);
  },

  isExpired(expiresAt: string): boolean {
    return new Date() >= new Date(expiresAt);
  },
};