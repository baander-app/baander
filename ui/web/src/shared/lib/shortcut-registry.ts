export const ShortcutCategory = {
  Transport: 'Transport',
  Volume: 'Volume',
  Navigation: 'Navigation',
  Search: 'Search',
  MediaType: 'Media Type',
  ViewMode: 'View Mode',
  Queue: 'Queue & Panel',
  General: 'General',
  ListNav: 'List Navigation',
} as const

export type ShortcutCategory = (typeof ShortcutCategory)[keyof typeof ShortcutCategory]

/** Key display strings per platform: key names joined with "+", such as "Cmd+K". */
export interface PlatformKeys {
  mac: string
  default: string
}

export interface ShortcutEntry {
  id: string
  category: ShortcutCategory
  keys: PlatformKeys
  description: string
  /** Key matcher: returns true if this KeyboardEvent matches the shortcut */
  matches: (e: KeyboardEvent) => boolean
  /** Action to dispatch when the shortcut fires */
  action?: (e: KeyboardEvent) => void
  /** Predicate to enable/disable at runtime (e.g. only when track playing) */
  enabled?: () => boolean
  /** If true, hide from the cheat-sheet overlay (e.g. duplicates) */
  hidden?: boolean
}

// ── Internal store ──

const registry = new Map<string, ShortcutEntry>()

// ── Platform detection ──

let _isMac: boolean | undefined

export function isPlatformMac(): boolean {
  if (_isMac !== undefined) return _isMac
  if (typeof navigator === 'undefined') return false
  _isMac = /Mac|iPod|iPhone|iPad/.test(navigator.platform ?? navigator.userAgent)
  return _isMac
}

/** Reset platform cache (useful for testing). */
export function resetPlatformCache(): void {
  _isMac = undefined
}

// ── Registry API ──

export function registerShortcut(entry: ShortcutEntry): void {
  registry.set(entry.id, entry)
}

export function unregisterShortcut(id: string): void {
  registry.delete(id)
}

export function clearRegistry(): void {
  registry.clear()
}

export function getShortcutsByCategory(): Map<ShortcutCategory, ShortcutEntry[]> {
  const map = new Map<ShortcutCategory, ShortcutEntry[]>()

  // Initialize all categories in enum order
  for (const cat of Object.values(ShortcutCategory)) {
    map.set(cat, [])
  }

  for (const entry of registry.values()) {
    if (entry.hidden) continue
    const list = map.get(entry.category)
    if (list) {
      list.push(entry)
    }
  }

  return map
}

export function getShortcutDisplay(id: string): PlatformKeys | null {
  const entry = registry.get(id)
  return entry?.keys ?? null
}

export function getAllShortcuts(): ShortcutEntry[] {
  return Array.from(registry.values())
}

export function getShortcut(id: string): ShortcutEntry | undefined {
  return registry.get(id)
}

// ── Key display parsing ──

/**
 * Split a key display string into its keys, one per <kbd> element. Keys are named in
 * words and joined with "+", such as "Cmd+ArrowRight" or "Shift+Enter"; the
 * cheat-sheet decides which names it draws as icons.
 */
export function parseKeyDisplay(display: string): string[] {
  if (!display) return []

  return display.split('+').map((key) => key.trim())
}
