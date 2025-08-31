import type { MenuItemConstructorOptions } from 'electron';

export function applyWinLinuxConventions(template: MenuItemConstructorOptions[]): MenuItemConstructorOptions[] {
  // Windows/Linux conventions are already covered by our sections; hook for future tweaks.
  return template;
}
