import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useCallback, useSyncExternalStore } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

import { VALID_ACCENTS, type AccentColor } from '@/shared/theme/theme.types'

export type { AccentColor }
export const VALID_COLORS = VALID_ACCENTS
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
  const coordinator = useLiveCoordinator()
  const color = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)

  const setColor = useCallback(async (newColor: AccentColor) => {
    applyColor(newColor)
    localStorage.setItem(STORAGE_KEY, newColor)

    window.dispatchEvent(new StorageEvent('storage', { key: STORAGE_KEY }))

    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['preferences'])
    try {
      await AXIOS_INSTANCE.put('/api/user/accent-color/', { color: newColor })
    } catch {
      return
    } finally {
      release?.()
    }
    if (!isCurrentOwnership(owner)) return
  }, [coordinator])

  const applyOnMount = useCallback(() => {
    applyColor(color)
  }, [color])

  return { color, setColor, applyOnMount }
}
