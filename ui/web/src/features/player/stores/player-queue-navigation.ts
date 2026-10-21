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
  const order = shuffle ? shuffleBag : queue.map((_, index) => index)
  const position = order.indexOf(currentIndex)
  const following = order.slice(position + 1)
  const candidates = repeat === 'all' ? [...following, ...order.slice(0, position + 1)] : following
  return candidates.find((index) => queue[index]?.available === true) ?? null
}
