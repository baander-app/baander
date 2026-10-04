import type { Track, RepeatMode } from './player-types'

/**
 * Generate a Fisher-Yates shuffle bag — a random permutation of indices.
 * If `excludeIndex` is given, it is placed first (current track plays first on enable).
 */
export function generateShuffleBag(queueLength: number, excludeIndex?: number): number[] {
  const indices = Array.from({ length: queueLength }, (_, i) => i)
  if (excludeIndex !== undefined && excludeIndex >= 0 && excludeIndex < queueLength) {
    indices.splice(indices.indexOf(excludeIndex), 1)
  }
  for (let i = indices.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[indices[i], indices[j]] = [indices[j], indices[i]]
  }
  if (excludeIndex !== undefined && excludeIndex >= 0 && excludeIndex < queueLength) {
    indices.unshift(excludeIndex)
  }
  return indices
}

/**
 * Resolve the next track index considering shuffle bag and repeat mode.
 * Returns null when the queue should stop (end of non-repeating queue).
 */
export function resolveNextIndex(
  queue: Track[],
  currentIndex: number,
  shuffle: boolean,
  repeat: RepeatMode,
  shuffleBag: number[],
): number | null {
  if (queue.length === 0) return null
  if (shuffle) {
    const bagIdx = shuffleBag.indexOf(currentIndex)
    const nextBagIdx = bagIdx + 1
    if (nextBagIdx < shuffleBag.length) return shuffleBag[nextBagIdx]
    if (repeat === 'all') return shuffleBag[0]
    return null
  }
  if (currentIndex < queue.length - 1) return currentIndex + 1
  if (repeat === 'all') return 0
  return null
}
