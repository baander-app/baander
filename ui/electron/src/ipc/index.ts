import { registerConfigIpc } from './modules/config.ipc';
import { registerDeepLinkIpc } from './modules/deep-link.ipc';
import { registerDiscoveryIpc } from './modules/discovery.ipc';
import { registerPlaybackIpc } from './modules/playback.ipc';
import { registerWindowIpc } from './modules/window.ipc';

export type IpcContext = {
  rendererOrigin: string;
  getServerUrlSync: () => string | null;
  deepLinkService?: {
    getPendingUrl: () => string | null;
    clearPendingUrl: () => void;
  };
};

let registered = false;

export function registerIpc(ctx: IpcContext) {
  if (registered) return;
  registered = true;
  // Add additional IPC module registrars here as your app grows
  registerConfigIpc(ctx);
  registerWindowIpc();
  registerDeepLinkIpc(ctx);
  registerDiscoveryIpc();
  registerPlaybackIpc();
}
