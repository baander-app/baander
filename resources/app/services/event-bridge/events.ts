import { CreateNotification } from '@/app/modules/notifications/models.ts';

export interface AuthEvents {
  'auth:login': {
    tokens: { accessToken: string; refreshToken: string | null; },
    session_id: string,
    expires_in: number,
  };
  'auth:logout': void;
  'auth:token-refresh': { tokens: any };
  'auth:session-expired': void;
}

export interface AppEvents {
  'app:theme-change': { theme: 'light' | 'dark' };
  'app:language-change': { language: string };
  'app:notification': { notification: CreateNotification };
}

export type EventMap = AuthEvents & AppEvents;

export type EventCallback<T = any> = (data: T) => void;
