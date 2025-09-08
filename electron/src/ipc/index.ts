import { registerConfigIpc } from './modules/config.ipc';
import { registerDeepLinkIpc } from './modules/deep-link.ipc';

export type IpcContext = {
  rendererOrigin: string;
  getServerUrlSync: () => string | null;
  deepLinkService?: {
    getPendingUrl: () => string | null;
    clearPendingUrl: () => void;
  };
};

export function registerIpc(ctx: IpcContext) {
  // Add additional IPC module registrars here as your app grows
  registerConfigIpc(ctx);
  registerDeepLinkIpc(ctx);
}
