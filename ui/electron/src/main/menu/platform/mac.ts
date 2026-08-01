import type { MenuItemConstructorOptions } from 'electron';

export function applyMacConventions(template: MenuItemConstructorOptions[]): MenuItemConstructorOptions[] {
  // On macOS, Electron expects an App menu at template[0]; our appSection already delivers it.
  // Here you can add further macOS adjustments if needed.
  return template;
}
