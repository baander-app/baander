import { handleTrusted } from '../guard';
import type { IpcContext } from '../index';

export function registerDeepLinkIpc(ctx: IpcContext) {
  handleTrusted('baander:deeplink:get-pending-url', () => {
    return ctx.deepLinkService?.getPendingUrl() || null;
  });

  handleTrusted('baander:deeplink:clear-pending-url', () => {
    ctx.deepLinkService?.clearPendingUrl();
    return true;
  });
}
