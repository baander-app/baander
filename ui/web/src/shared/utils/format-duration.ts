/** Format seconds as m:ss. Returns '—' for NaN/Infinity/negative. */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '—'

  const totalSeconds = Math.round(seconds)
  const minutes = Math.floor(totalSeconds / 60)
  const secs = totalSeconds % 60

  return `${minutes}:${secs.toString().padStart(2, '0')}`
}
