import type { IpcMainInvokeEvent } from 'electron';
import { getMainWindow } from '../../main/windows/main-window';
import { getTrustedWindow } from '../../main/security/renderer-trust';
import { handleTrusted } from '../guard';

function ownMainWindow(event: IpcMainInvokeEvent) {
  const window = getTrustedWindow(event);
  if (!window || window !== getMainWindow()) throw new Error('Unauthorized window control sender');
  return window;
}

export function registerWindowIpc() {
  handleTrusted('baander:window:minimize', event => ownMainWindow(event).minimize());
  handleTrusted('baander:window:maximize', event => {
    const window = ownMainWindow(event);
    if (window.isMaximized()) window.unmaximize();
    else window.maximize();
  });
  handleTrusted('baander:window:close', event => ownMainWindow(event).close());
  handleTrusted('baander:window:is-maximized', event => ownMainWindow(event).isMaximized());
  handleTrusted('baander:window:set-background-color', (event, color: unknown) => {
    const window = ownMainWindow(event);
    if (typeof color !== 'string' || !/^#(?:[\da-f]{3}|[\da-f]{4}|[\da-f]{6}|[\da-f]{8})$/i.test(color)) throw new Error('Invalid background color');
    window.setBackgroundColor(color);
  });
}
