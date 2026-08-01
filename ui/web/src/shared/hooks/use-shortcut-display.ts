import { getShortcutDisplay, isPlatformMac } from '@/shared/lib/shortcut-registry'
import { parseKeyDisplay } from '@/shared/lib/shortcut-registry'

/**
 * Returns the platform-appropriate key display tokens for a shortcut ID.
 * Returns null if the shortcut is not registered.
 */
export function useShortcutDisplay(id: string): string[] | null {
  const display = getShortcutDisplay(id)
  if (!display) return null
  const raw = isPlatformMac() ? display.mac : display.default
  return parseKeyDisplay(raw)
}
