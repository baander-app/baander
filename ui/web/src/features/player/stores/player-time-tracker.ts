import { useSyncExternalStore } from 'react'

let currentTime = 0
const listeners = new Set<() => void>()
let bridge: ((time: number) => void) | null = null

export function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

function getSnapshot(): number {
  return currentTime
}

/** Register a bridge callback (e.g. Zustand setState) for dual-write. */
export function registerTimeBridge(fn: (time: number) => void): void {
  bridge = fn
}

/**
 * Update the current playback time. Writes to both the external store
 * (for React consumers via useCurrentTime) and the registered bridge
 * (Zustand for non-React consumers like use-session sync).
 */
export function updateTime(time: number): void {
  currentTime = time
  listeners.forEach((l) => l())
  bridge?.(time)
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
