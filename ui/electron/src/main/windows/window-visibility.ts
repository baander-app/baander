import type { BrowserWindow } from 'electron';

/** Whether the window is on screen: shown and not minimised. */
export function isShown(window: BrowserWindow): boolean {
  return window.isVisible() && !window.isMinimized();
}
