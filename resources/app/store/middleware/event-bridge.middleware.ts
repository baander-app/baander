import { eventBridge } from '@/services/event-bridge/bridge';
import { Action, Middleware } from '@reduxjs/toolkit';

// Define the shape of your Redux actions
interface AuthLoginSuccessAction extends Action<'auth/loginSuccess'> {
  payload: {
    user: any;
    tokens: any;
  };
}

interface AuthTokenRefreshAction extends Action<'auth/tokenRefresh'> {
  payload: {
    tokens: any;
  };
}

interface AppSetThemeAction extends Action<'app/setTheme'> {
  payload: {
    theme: 'light' | 'dark';
  };
}

type KnownActions =
  | AuthLoginSuccessAction
  | AuthTokenRefreshAction
  | AppSetThemeAction
  | Action<'auth/logout'>
  | Action<'auth/sessionExpired'>;

export const eventBridgeMiddleware: Middleware = () => (next) => (action: unknown) => {
  const result = next(action);

  // Type guard to check if action has expected structure
  const typedAction = action as KnownActions;

  // Map Redux actions to events
  switch (typedAction.type) {
    case 'auth/loginSuccess':
      if ('payload' in typedAction) {
        const loginAction = typedAction as AuthLoginSuccessAction;
        eventBridge.emit('auth:login', {
          tokens: loginAction.payload.tokens,
        });
      }
      break;

    case 'auth/logout':
      eventBridge.emit('auth:logout', undefined);
      break;

    case 'auth/tokenRefresh':
      if ('payload' in typedAction) {
        const refreshAction = typedAction as AuthTokenRefreshAction;
        eventBridge.emit('auth:token-refresh', {
          tokens: refreshAction.payload.tokens,
        });
      }
      break;

    case 'auth/sessionExpired':
      eventBridge.emit('auth:session-expired', undefined);
      break;

    case 'app/setTheme':
      if ('payload' in typedAction) {
        const themeAction = typedAction as AppSetThemeAction;
        eventBridge.emit('app:theme-change', {
          theme: themeAction.payload.theme,
        });
      }
      break;
  }

  return result;
};