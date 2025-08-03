
import { AuthLogin200, NewAccessTokenResource } from '@/libs/api-client/gen/models';

const LOCAL_STORAGE_KEY = 'baander_token';
const LOCAL_STORAGE_KEY_STREAM = 'baander_stream_token';

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

  getStreamToken(): NewAccessTokenResource | undefined {
    try {
      const token = localStorage.getItem(LOCAL_STORAGE_KEY_STREAM);
      return token ? JSON.parse(token) : undefined;
    } catch {
      // Clear corrupted data
      localStorage.removeItem(LOCAL_STORAGE_KEY_STREAM);
      return undefined;
    }
  },

  set(token: AuthLogin200): void {
    localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(token));
  },

  setStreamToken(token: NewAccessTokenResource): void {
    localStorage.setItem(LOCAL_STORAGE_KEY_STREAM, JSON.stringify(token));
  },

  clear(): void {
    localStorage.removeItem(LOCAL_STORAGE_KEY);
    localStorage.removeItem(LOCAL_STORAGE_KEY_STREAM);
  },

  isExpired(expiresAt: string): boolean {
    return new Date() >= new Date(expiresAt);
  },
};