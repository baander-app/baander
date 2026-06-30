/**
 * Human-readable formatters for admin/dashboard display.
 *
 * For the m:ss playback duration format, see `./format-duration`.
 * For relative time labels ("5m ago"), see `./format-relative-time`.
 */

/**
 * Format seconds into a compact human-readable duration.
 *
 * Examples: `45s`, `3m`, `2h 15m`, `1d 3h`
 *
 * Handles fractional input by rounding to the nearest second.
 * Returns `'0s'` for zero or negative input.
 */
export function formatDurationHuman(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) return '0s'

  const totalSeconds = Math.round(seconds)

  if (totalSeconds < 60) return `${totalSeconds}s`

  const days = Math.floor(totalSeconds / 86400)
  const hours = Math.floor((totalSeconds % 86400) / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)

  if (days > 0) {
    const parts = [`${days}d`]
    if (hours > 0) parts.push(`${hours}h`)
    return parts.join(' ')
  }

  if (hours > 0) {
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`
  }

  return `${minutes}m`
}

/**
 * Format seconds as an uptime string (always in d/h/m units).
 *
 * Examples: `3d 2h 15m`, `5h 30m`, `12m`
 *
 * Unlike `formatDurationHuman`, this always shows all relevant units
 * including the smallest (minutes) for server uptime display.
 */
export function formatUptime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0m'

  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)

  if (d > 0) return `${d}d ${h}h ${m}m`
  if (h > 0) return `${h}h ${m}m`
  return `${m}m`
}

/**
 * Format a byte count into a human-readable size string.
 *
 * Examples: `0 B`, `1.5 KB`, `2.3 GB`
 */
export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  if (!Number.isFinite(bytes) || bytes < 0) return '0 B'

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  const clampedIndex = Math.min(i, units.length - 1)
  return `${(bytes / Math.pow(1024, clampedIndex)).toFixed(1)} ${units[clampedIndex]}`
}
