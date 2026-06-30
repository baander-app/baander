import { useCallback, useSyncExternalStore } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export type AccentColor = 'white' | 'blue' | 'violet' | 'rose' | 'amber' | 'emerald' | 'cyan' | 'pink'

export const VALID_COLORS: AccentColor[] = ['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink']
const STORAGE_KEY = 'baander-accent-color'
const DEFAULT_COLOR: AccentColor = 'violet'

function applyColor(color: AccentColor): void {
  document.documentElement.setAttribute('data-accent', color)
}

function subscribe(callback: () => void): () => void {
  window.addEventListener('storage', callback)
  return () => window.removeEventListener('storage', callback)
}

function getSnapshot(): AccentColor {
  const stored = localStorage.getItem(STORAGE_KEY) as AccentColor | null
  return stored && VALID_COLORS.includes(stored) ? stored : DEFAULT_COLOR
}

function getServerSnapshot(): AccentColor {
  return DEFAULT_COLOR
}

export function useAccentColor() {
  const color = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)

  const setColor = useCallback(async (newColor: AccentColor) => {
    applyColor(newColor)
    localStorage.setItem(STORAGE_KEY, newColor)

    window.dispatchEvent(new StorageEvent('storage', { key: STORAGE_KEY }))

    try {
      await AXIOS_INSTANCE.put('/api/user/accent-color/', { color: newColor })
    } catch {
      // Non-critical
    }
  }, [])

  const applyOnMount = useCallback(() => {
    applyColor(color)
  }, [color])

  return { color, setColor, applyOnMount }
}
