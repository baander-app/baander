import { registerConfigIpc } from './modules/config.ipc';

export type IpcContext = {
  rendererOrigin: string;
};

export function registerIpc(ctx: IpcContext) {
  // Add additional IPC module registrars here as your app grows
  registerConfigIpc(ctx);
}
