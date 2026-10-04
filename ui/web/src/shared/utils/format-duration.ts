/** Format rounded seconds as mm:ss, or hh:mm:ss from one hour. Invalid input returns '—'. */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '—'

  const totalSeconds = Math.round(seconds)
  const minutes = Math.floor(totalSeconds / 60)
  const secs = totalSeconds % 60

  if (totalSeconds >= 3600) {
    const hours = Math.floor(totalSeconds / 3600)
    return `${hours.toString().padStart(2, '0')}:${(minutes % 60).toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
  }

  return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}
