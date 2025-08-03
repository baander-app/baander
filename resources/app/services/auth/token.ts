import { AuthLogin200, NewAccessTokenResource } from '@/libs/api-client/gen/models';

const LOCAL_STORAGE_KEY = 'baander_token';
const LOCAL_STORAGE_KEY_STREAM = 'baander_stream_token';

export const Token = {
  get() {
    const token = localStorage.getItem(LOCAL_STORAGE_KEY);
    if (!token) {
      return undefined;
    }

    return JSON.parse(token) as AuthLogin200;
  },
  getStreamToken() {
    const token = localStorage.getItem(LOCAL_STORAGE_KEY_STREAM);
    if (!token) {
      return undefined;
    }

    return JSON.parse(token) as NewAccessTokenResource;
  },
  set(token: AuthLogin200) {
    localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(token));
  },
  setStreamToken(token: NewAccessTokenResource) {
    localStorage.setItem(LOCAL_STORAGE_KEY_STREAM, JSON.stringify(token));
  },
  clear() {
    localStorage.removeItem(LOCAL_STORAGE_KEY);
    localStorage.removeItem(LOCAL_STORAGE_KEY_STREAM);
  },
};

export function isTokenExpired(expiresAt: string): boolean {
  // Convert expiresAt to a Date object
  const tokenExpiryDate = new Date(expiresAt);

  // Get current date
  const now = new Date();

  // If the current date is equal to or later than the expiry date,
  // it means that the token is expired or about to expire
  return now >= tokenExpiryDate;
}