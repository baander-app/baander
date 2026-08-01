import { useCallback, useSyncExternalStore } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export type ThemeMood = 'dark' | 'warm' | 'cool' | 'balanced'

export const VALID_MOODS: ThemeMood[] = ['dark', 'warm', 'cool', 'balanced']
const STORAGE_KEY = 'baander-theme-mood'

function getDefaultMood(): ThemeMood {
  if (window.matchMedia('(prefers-color-scheme: light)').matches) {
    return 'balanced'
  }
  return 'dark'
}

function applyMood(mood: ThemeMood): void {
  document.documentElement.setAttribute('data-theme', mood)

  // Toggle theme-transitioning class for smooth CSS transition
  document.documentElement.classList.add('theme-transitioning')
  setTimeout(() => {
    document.documentElement.classList.remove('theme-transitioning')
  }, 200)
}

function subscribe(callback: () => void): () => void {
  window.addEventListener('storage', callback)
  return () => window.removeEventListener('storage', callback)
}

function getSnapshot(): ThemeMood {
  const stored = localStorage.getItem(STORAGE_KEY) as ThemeMood | null
  return stored && VALID_MOODS.includes(stored) ? stored : getDefaultMood()
}

function getServerSnapshot(): ThemeMood {
  return 'dark'
}

export function useThemeMood() {
  const mood = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)

  const setMood = useCallback(async (newMood: ThemeMood) => {
    applyMood(newMood)
    localStorage.setItem(STORAGE_KEY, newMood)

    // Dispatch storage event so other tabs pick it up
    window.dispatchEvent(new StorageEvent('storage', { key: STORAGE_KEY }))

    // Persist to backend (fire-and-forget, non-blocking)
    try {
      await AXIOS_INSTANCE.put('/api/user/theme-mood/', { mood: newMood })
    } catch {
      // Non-critical — local state is already applied
    }

    // Persist to Electron config-store (if running in Electron)
    try {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (window as any).BaanderElectron?.config?.setThemeMood?.(newMood)
    } catch {
      // Non-critical — web-only environment
    }
  }, [])

  const applyOnMount = useCallback(() => {
    applyMood(mood)
  }, [mood])

  return { mood, setMood, applyOnMount }
}
