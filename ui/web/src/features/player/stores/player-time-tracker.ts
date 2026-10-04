import { useSyncExternalStore } from 'react'

let currentTime = 0
const listeners = new Set<() => void>()

export function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

function getSnapshot(): number {
  return currentTime
}

/** The sole playback clock. Identical and invalid updates do not notify consumers. */
export function updateTime(time: number): void {
  if (!Number.isFinite(time)) return
  const next = Math.max(0, time)
  if (next === currentTime) return
  currentTime = next
  listeners.forEach(listener => listener())
}

/** Read current time outside React — no re-render. */
export function getCurrentTime(): number {
  return currentTime
}

/**
 * React hook for subscribing to high-frequency playback time updates.
 * Isolated from Zustand — only components calling this hook re-render at ~4Hz.
 */
export function useCurrentTime(): number {
  return useSyncExternalStore(subscribe, getSnapshot, () => 0)
}
