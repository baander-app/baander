import { NewAccessTokenResource } from '@/libs/api-client/gen/models';
import { CreateNotification } from '@/modules/notifications/models.ts';

export interface AuthEvents {
  'auth:login': {
    tokens: { accessToken: NewAccessTokenResource; refreshToken: NewAccessTokenResource; },
    sessionId: string
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
